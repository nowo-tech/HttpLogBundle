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
| Log injection / XSS in admin | Twig auto-escaping; treat stored bodies as untrusted when displaying |
| Storage growth / DoS | `max_body_bytes`, sampling, retention purge |
| Async queue exposure | Messenger transport access controls; messages contain redacted capture arrays |

## Redaction defaults

Out of the box, these are redacted (see `Configuration` defaults):

**Headers:** `Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`

**Query parameters:** `password`, `token`, `api_key`

**JSON paths:** `*.password`, `*.token`

Extend lists for your application. Redaction runs **before** Doctrine persist.

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

Adjust the path if you change `web_ui.path_prefix`. Combine with your existing firewall so only authenticated admins reach the UI.

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
| **Input / output** | Stored bodies treated as untrusted in Twig (auto-escaping); export files may contain PII — protect filesystem access. |
| **Dependencies** | `composer audit` run; issues triaged. |
| **Logging** | Messenger/async failures log message type and ids only — no raw Authorization, Cookie, or body payloads. |
| **Messenger** | Retry and failure transport documented for `PersistHttpLogMessage`, `ExportHttpLogMessage`, and `PurgeHttpLogMessage`. |
| **Permissions / exposure** | Admin UI, export, and purge require `access_roles` or custom `HttpLogAccessCheckerInterface`. |
| **AI security audit (REQ-SEC-004)** | Grade **Pass (good)** / risk **Low** (2026-08-03). See [AI security audit](#ai-security-audit). |

Record confirmation in the release PR or tag notes.

## AI security audit

| Field | Value |
| ----- | ----- |
| Date | 2026-08-20 (re-audit; prior 2026-08-03) |
| Grade | Pass (good) |
| Risk | Low |
| Method | Static review of capture path, admin CSRF/access checker, redaction defaults, Messenger payloads, Flex `when@prod` (sampling 0.25, bodies off, retention 14d, `ROLE_ADMIN`) |
| Open residuals | None (Critical/High). App-owned: schedule `nowo:http-log:purge`; protect export files; do not disable redaction / enable bodies without review. |

See [CONFIGURATION.md](CONFIGURATION.md) and [USAGE.md](USAGE.md).
