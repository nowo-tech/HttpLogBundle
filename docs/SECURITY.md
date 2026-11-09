# Security

Security considerations for HTTP log capture, storage, and the admin UI.

## Table of contents

- [Threat model](#threat-model)
- [Redaction defaults](#redaction-defaults)
- [Body capture defaults](#body-capture-defaults)
- [Admin UI guard (REQ-UI-002)](#admin-ui-guard-req-ui-002)
- [Secrets and credentials](#secrets-and-credentials)
- [Operational guidance](#operational-guidance)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit](#ai-security-audit)

## Threat model

| Risk | Mitigation |
| --- | --- |
| Sensitive data in logs | Redaction of headers, query params, JSON keys; request body off by default |
| Unauthorized log access | Symfony Security + `access_roles` / custom `HttpLogAccessCheckerInterface` |
| CSRF on purge / delete / export | Symfony form CSRF on every admin action form. **Fails closed**: `CsrfProtectionPass` refuses to compile the container when the admin UI is enabled without `security.csrf.token_manager` + `form.type_extension.csrf`, or with `framework.form.csrf_protection: false` (action forms declaring their own CSRF options would otherwise accept tokenless POSTs) |
| Log injection / XSS in admin | Twig auto-escaping; treat stored bodies as untrusted when displaying |
| Storage growth / DoS | `max_body_bytes`, sampling, retention purge |
| Async queue exposure | Messenger transport access controls; messages contain redacted capture arrays |
| Secrets in outbound / mailer rows (HL-1) | Allowlist, not redaction: a usage row has no column for headers, bodies, query strings, user info, addresses, subjects or exception messages; every stored value is validated against its charset / length and replaced by NULL (never cut). The Messenger payload is built from the same allowlist |
| Logging breaking the host (HL-1) | Every capture step is wrapped: a failure logs one warning (exception class only) and drops the capture; writes are DBAL inserts after the request / message boundary, never a `flush()` of the shared manager |
| Usage rows of another channel or tenant (HL-1) | Optional `HttpLogChannelAccessCheckerInterface` hides channels from list, entry page (404), export (synchronous and background: each background export remembers its channels and is listed / served only to users allowed all of them) and purge; tenant scoping of host pages stays with the host |
| Secrets in outbound paths and hosts (HL-1) | Path segments are kept only when they are versions or known path words, else templated (`{seg}`, `{x}`, `{id}`, `{uuid}`); host names are stored as is, so list secret hosts in `outbound.exclude_hosts` and give webhook providers `path_depth: 0` |
| Secrets in named-channel rows (HL-1 1.16) | Allowlist: the route path template (never the raw path: tokens in `/share/{token}` stay out), no query string, headers or bodies, a truncated client IP by default; the client's `X-Request-Id` is neither stored nor a dedupe key (it cannot suppress rows or counts) |
| Token leakage through the resolver hook (HL-1 1.16) | `HttpLogRequestContextResolution` drops (stores NULL, warns with the resolver class and field only) any credential kind / id, tenant or project key that fails the opaque-key rule or equals, contains or starts (6+ chars) a credential of the request: the `Authorization` value (whole, after its scheme, decoded Basic pair and password), each `redaction.headers` value and cookie, each `redaction.query_params` value. The same guard drops a user identifier equal to the request's credential. Credentials sent in other headers are guarded only once listed in `redaction.headers` |
| Resolver changing the request (HL-1 1.16) | The resolver runs before the controller only when reading the token cannot start a lazy firewall's authentication (`security.untracked_token_storage` has no pending initializer); otherwise at `kernel.terminate`. A resolver that throws is skipped (exception class logged). The token is passed only behind a firewall with security enabled (worker rule of 1.14) |
| Another tenant's usage on a host page (HL-1 1.16) | `HttpLogUsageReaderInterface` requires an explicit scope (`forTenant()` / `platform()`, no default); a tenant query filters on the exact key (binary comparison on MySQL, whose rollup key columns are `utf8mb4_bin`, so keys differing in case are separate buckets). Authorisation of the page stays with the host |
| Double counting or suppressed counts (HL-1 1.16) | Exactly-once rollup: the per-call row (unique server event id) or the ledger row and the bucket upsert in one transaction; a redelivery is a unique violation and counts nothing. The ledger forgets an event after `rollup.dedupe_days`: replay the failure transport within it |
| Hiding usage from the rollup (HL-1 1.16, audit JA) | Short request values (< 8 characters) are secrets only by exact equality, so `?token=-` cannot blank the context (JA-M1); channel prefixes and web ignores use the decoded path, so `/%61pi/…` cannot leave the API channel (JA-M2) |
| Write hot spot (HL-1 1.16, JA-L3) | One bucket serialises its writes; failed-auth requests of a channel share the `''` bucket. Until the §9.6 batch handler lands: `async: true` on a dedicated transport and host rate limits for public channels |
| Resolver reading client input (JA-I7) | The bundle takes tenant and credential only from the resolver. Resolve them from the authenticated credential, never from a header or route parameter, and return null when authentication failed; never derive a credential id from the token except as a one-way hash (JA-I1) |
| Blanking a known context value (HL-1 1.16.1, JAd-L1) | Only the request's credential (`Authorization`, `redaction.credential_headers`) is matched by contains / prefix, at any length; other redacted headers, params and cookies are compared with the credential id by equality only and never with the tenant, project or kind, so `?token=<own tenant UUID>` keeps the tenant. Residual: a client can blank its own credential id by sending it verbatim as a redacted value (documented) |
| Personal data in the rollup (HL-1 1.16) | Buckets hold no user identifier, IP, path or host: only channel, provider, credential kind / id and tenant keys plus counters. `HttpLogUsageEraserInterface` erases a credential (rows deleted, buckets folded into `~erased`) or a tenant (rows and buckets deleted); `HttpLogPersonalDataEraserInterface` also covers named-channel rows |

## Redaction defaults

Out of the box, these are redacted (see `Configuration` defaults):

**Headers:** `Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`

**Query parameters / form fields:** `password`, `_password`, `token`, `_token`, `_csrf_token`, `api_key`

**JSON paths:** `*.password`, `*._password`, `*.token`, `*._token`, `*._csrf_token`

Extend lists for your application. Redaction runs **before** Doctrine persist and **before** `max_body_bytes` truncation, so a truncated body never contains a secret that full-body redaction would have removed. JSON, `application/x-www-form-urlencoded`, and multipart text fields are redacted; a JSON-looking body that cannot be parsed is stored as `[REDACTED-UNPARSEABLE]`.

**Access roles:** an empty `security.access_roles` list denies everyone (fail closed). Use `allow_unauthenticated: true` (demos only) or a custom `access_checker` to grant access deliberately.

## Body capture defaults

| Setting | Default | Rationale |
| --- | --- | --- |
| `capture.request_body` | `false` | Request bodies often contain credentials or PII |
| `response_body_by_type.html` | `false` | HTML pages may embed tokens or personal data |
| `response_body_by_type.json` | `true` | API debugging; still subject to JSON redaction |
| `response_body_by_type.binary` | `false` | Avoid storing uploads/downloads |

Enable additional types only with explicit review.

## Admin UI guard (REQ-UI-002)

The admin UI at `web_ui.path_prefix` (default `/admin/http-log`) must not be public in production.

**Default configuration:**

```yaml
nowo_http_log:
    security:
        access_roles:
            - ROLE_ADMIN
        allow_unauthenticated: false
```

Requirements:

1. Install `symfony/security-bundle`.
2. Configure firewalls and `access_control` for the admin path (see snippet below).
3. Optionally provide a custom `access_checker` service implementing `HttpLogAccessCheckerInterface` for separate export/purge permissions.

**Symfony `access_control` (host app):**

```yaml
# config/packages/security.yaml
security:
    access_control:
        - { path: ^/admin/http-log, roles: ROLE_ADMIN }
```

Adjust the path if you change `web_ui.path_prefix` (the admin routes move with it). Combine with your existing firewall so only authenticated admins reach the UI.

Setting `allow_unauthenticated: true` is supported for local demos only.

## Secrets and credentials

- **Do not** disable redaction for `Authorization` or session cookies in production.
- **Do not** commit exported CSV/JSON logs containing production data.
- Store the SQLite/DB file with appropriate filesystem permissions.
- Review `ignore_path_prefixes` so health checks and auth callbacks are excluded if they carry tokens in query strings.

## Operational guidance

- Run retention purge on a schedule (**cron** + `nowo:http-log:purge`, or the Messenger `PurgeHttpLogMessage` handler) so stored request/response data does not grow without bound.
- Use `sampling_rate` on high-traffic endpoints.
- Prefer async persistence so logging never blocks the response path (failures are logged, not thrown to the client).
- Audit who has `ROLE_ADMIN` (or custom checker roles) with the same rigor as production database access.

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
|------|--------|
| **SECURITY.md** | This document is current and linked from the README where applicable. |
| **`.gitignore` and `.env`** | `.env`, `.env.dev`, and local env files are ignored; no committed secrets. |
| **No secrets in repo** | No API keys, passwords, or tokens in tracked files or exported log samples. |
| **Redaction defaults** | Default header/query/JSON redaction lists remain enabled; request body capture stays off by default. |
| **Admin UI exposure** | `security.allow_unauthenticated` is `false` in recipe defaults; document `access_control` for `^/admin/http-log` (`ROLE_ADMIN`) and schedule purge cron. |
| **Input / output** | Stored bodies treated as untrusted in Twig (auto-escaping); export files may contain PII: background exports live in the private `export.directory` (`0700` / `0600`, random names, deleted after `export.ttl_hours`) and are downloaded only by users allowed to export; CLI exports are created `0600`. |
| **Dependencies** | `composer audit` run; issues triaged. |
| **Logging** | Messenger/async failures log message type and ids only — no raw Authorization, Cookie, or body payloads. |
| **Messenger** | Retry and failure transport documented for `PersistHttpLogMessage`, `ExportHttpLogMessage`, and `PurgeHttpLogMessage`. |
| **Permissions / exposure** | Admin UI, export, and purge require `access_roles` or custom `HttpLogAccessCheckerInterface`. |
| **AI security audit (REQ-SEC-004)** | Grade **Pass (good)** / risk **Low** (2026-08-03). See [AI security audit](#ai-security-audit). |

Record confirmation in the release PR or tag notes.

## AI security audit

**HL-1 named channels, rollup, retention and erasure (1.16.0, slices S4–S6):** audit A2 (Appendix JA, 2026-11-09)
Pass-Medium on `d7f99ad`; JA-M1, JA-M2, JA-L1, JA-L2, JA-I2, JA-I3, JA-I4 fixed, JA-L3 and the other Info items
documented. Delta JA on `a7bff4d`: **Pass-Low** (JA-M1, JA-M2, JA-L1, JA-L2 fixed; JA-L3 documented). Residual JAd-L1: a client that knows its own tenant UUID, credential id or kind (or an 8-character slice of it) can blank that field by sending it as `?token=<value>`, so the call drops out of tenant-scoped usage and `lastUsed()`; nothing leaks. Fix (contains/prefix only for the Authorization value at any length, equality elsewhere) follows in 1.16.1.

**HL-1 usage capture (1.15.0, slices S1–S3):** audit A1 (Appendix IX, 2026-11-09) Pass-Medium on `df15dae`;
IX-M1, IX-L1–L3, IX-I2 and IX-I5 fixed, IX-I1 / IX-I3 documented. Delta IX on `8270a0e`: **Pass**. Open Info: IXd-I1 (a multi-client `stream()` delivers one client group after another), IXd-I2 (`exclude_hosts` misses a trailing dot or an unlisted non-default port; such a call is logged with a templated path).

| Field | Value |
| ----- | ----- |
| Date | 2026-08-20 (re-audit; prior 2026-08-03) |
| Grade | Pass (good) |
| Risk | Low |
| Method | Static review of capture path, admin CSRF/access checker, redaction defaults, Messenger payloads, Flex `when@prod` (sampling 0.25, bodies off, retention 14d, `ROLE_ADMIN`) |
| Open residuals | None (Critical/High). App-owned: schedule `nowo:http-log:purge`; protect export files; do not disable redaction / enable bodies without review. |

See [CONFIGURATION.md](CONFIGURATION.md) and [USAGE.md](USAGE.md).
