# Baseline specification — Http Log Bundle

**Package:** `nowo-tech/http-log-bundle`  
**Namespace:** `Nowo\HttpLogBundle`  
**Config alias:** `nowo_http_log`  
**Status:** Initial baseline (unreleased scaffold)

## Overview

Http Log Bundle records HTTP request/response pairs for Symfony applications. Capture runs on `kernel.terminate`, applies redaction and body policies, persists via Doctrine (sync or Messenger async), and exposes a secured admin UI plus CLI for export and purge.

## User scenarios (`US-*`)

### US-01 — Capture HTTP traffic (Priority: P1)

As an operator, I capture HTTP requests and responses on kernel terminate with configurable sampling and environment filters so I can audit traffic without blocking responses.

**Acceptance:** Eligible requests produce `HttpLogEntry` rows with method, path, route, status, duration, IP, user, and headers after the response is sent.

### US-02 — Redact sensitive data (Priority: P1)

As a security officer, I redact sensitive headers, query parameters, and JSON body keys before storage so logs do not retain credentials or PII by default.

**Acceptance:** Configured header/query/JSON rules replace values with `[REDACTED]` before Doctrine persist.

### US-03 — Store bodies by content type (Priority: P2)

As an operator, I store response bodies selectively by content type (html, json, soap, xml, text, binary, other) with truncation so debugging is useful without unbounded storage.

**Acceptance:** Only enabled types are stored, subject to `max_body_bytes`; request bodies remain opt-in.

### US-04 — Browse logs in admin UI (Priority: P1)

As an admin, I browse and filter captured entries in a secured web UI at `/admin/http-log` so I can investigate incidents.

**Acceptance:** Authorized users can list, filter, view detail, export, and purge entries; unauthorized access is denied (REQ-UI-002).

### US-05 — Export logs (Priority: P2)

As an admin, I export filtered logs to CSV or JSON (sync or async via Messenger) for offline analysis.

**Acceptance:** Export respects filters and row caps; larger jobs can run asynchronously.

### US-06 — Purge by retention (Priority: P2)

As an operator, I purge old entries by retention policy or on demand so storage stays bounded.

**Acceptance:** Entries older than `retention.days` are deleted via CLI, UI, or async handler.

### US-07 — Run demo and CI smoke (Priority: P3)

As a maintainer, I run the Symfony 8 FrankenPHP demo and CI smoke checks to verify the bundle boots in a real app.

**Acceptance:** `make demo-smoke` returns HTTP 200 from the demo home page.

## Functional requirements (`FR-*`)

### Capture (`FR-CAP-*`)

| ID | Requirement |
| --- | --- |
| FR-CAP-001 | On `kernel.terminate`, eligible requests create an `HttpLogEntry` with method, path, route, status, duration, IP, user, and headers |
| FR-CAP-002 | Store a bundle-generated random hex request id (the dedupe key); the client `X-Request-Id` is never used for it and is kept only in the captured request headers |
| FR-CAP-003 | Store response bodies only for content types enabled in `capture.response_body_by_type`, subject to `max_body_bytes` |
| FR-CAP-004 | Request bodies are off by default; stored only when `capture.request_body: true` |

### Redaction (`FR-RED-*`)

| ID | Requirement |
| --- | --- |
| FR-RED-001 | Apply header, query, and JSON path redaction before persist; redacted values become `[REDACTED]` |

### Admin UI (`FR-ADM-*`, `FR-UI-*`)

| ID | Requirement |
| --- | --- |
| FR-ADM-001 | Secured admin UI at `web_ui.path_prefix` supports list, filter, detail, export, and purge |
| FR-UI-001 | Admin UI supports configurable CSS framework and layout integration |
| FR-UI-002 | Admin UI requires Symfony Security or custom `HttpLogAccessCheckerInterface` unless explicitly allowed |
| FR-UI-003 | Admin pages render on the UiKit page contract (REQ-UI-003, spec [004](../004-ui-page-contract/spec.md)): `View\AdminPages` builds the list/show view models, `nowo-http-log-admin.css` only sizes the payload blocks, and `LegacyThemeBridge` maps changed 1.x `web_ui` chrome keys into UiKit theme slots until 2.0 |
| FR-TWIG-001 | Application Twig overrides under `templates/bundles/NowoHttpLogBundle/` take precedence over bundle templates |

### Export & purge (`FR-EXP-*`, `FR-PUR-*`)

| ID | Requirement |
| --- | --- |
| FR-EXP-001 | Export filtered rows to CSV or JSON (UI, CLI, or async Messenger) up to configured sync limits |
| FR-PUR-001 | Purge entries older than `retention.days` via CLI, UI, or async handler, in keyset batches of `retention.purge_batch_size` (1.7.0, spec [003](../003-kit-ignore-paths-keyset-purge/spec.md)) |

### Filtering (`FR-IGN-*`)

| ID | Requirement |
| --- | --- |
| FR-IGN-001 | Skip capture when `ignore_routes` or `ignore_path_prefixes` match (plus the kit defaults while `ignore_default_path_prefixes` is true, 1.7.0, spec [003](../003-kit-ignore-paths-keyset-purge/spec.md)) |
| FR-IGN-002 | When `ignore_unrouted` is `true`, skip capture for requests without a matched `_route` (unrouted 404s); default `false` keeps logging them |

### Configuration & DI (`FR-CFG-*`, `FR-DI-*`, `FR-BUNDLE-*`)

| ID | Requirement |
| --- | --- |
| FR-BUNDLE-001 | Register bundle services, routes, and Twig namespace via Symfony extension |
| FR-CFG-001 | Expose strict `nowo_http_log` configuration tree with safe defaults |
| FR-CFG-002 | Load services, routes, and translations from `Resources/config` |
| FR-DI-001 | Wire services with constructor injection (Clock, Logger where applicable) |

### Persistence (`FR-ORM-*`)

| ID | Requirement |
| --- | --- |
| FR-ORM-001 | Persist `HttpLogEntry` via Doctrine with paginated repository queries |
| FR-ORM-002 | `doctrine.table_name_prefix` (default `nowo_http_log_`) replaces the `nowo_http_log_` part of the `HttpLogEntry` table name, then `doctrine.table_prefix` prefixes the table and its index names, through `TablePrefixListener` (registered only when an option differs from its default; REQ-ORM-002) |

### FrankenPHP worker / long-lived kernel (`FR-WORKER-*`)

| ID | Requirement |
| --- | --- |
| FR-WORKER-001 | Bundle services hold no per-request mutable state; capture timing and request id live in request attributes |
| FR-WORKER-002 | After persisting a log entry, detach it so the shared EntityManager identity map does not grow under worker mode without kernel reset |
| FR-WORKER-003 | Resolve and reset a closed EntityManager via `ManagerRegistry` so a failed log flush does not leave the worker unable to use Doctrine |
| FR-WORKER-004 | Record `userIdentifier` only when a firewall with security enabled handled the current request (no stale `TokenStorage` under scenario B) |
| FR-WORKER-005 | Repository queries resolve the EntityManager from `ManagerRegistry` on every call; export/purge/admin detach or avoid hydrating rows that would linger across requests |

### Messenger (`FR-MSG-*`)

| ID | Requirement |
| --- | --- |
| FR-MSG-001 | Async handlers for persist, export, and purge with documented retry/failure transport expectations |

### Outbound and mailer capture (`FR-OUT-*`, `FR-MAIL-*`)

| ID | Requirement |
| --- | --- |
| FR-OUT-001 | With `outbound.enabled`, every `http_client.client` service selected by `outbound.clients` / `outbound.exclude_clients` is decorated (default priority -1024, outermost) and records one row per logical call; options, headers, chunks, exceptions and laziness are untouched and the body is never read |
| FR-OUT-002 | Each call is attributed to a provider (per-request option, wrapped client, client service id, exact host, wildcard host, else `other`); the row stores scheme, host and a path template, never the query string, fragment or user info; errors are always kept, successes by `sampling_rate` |
| FR-OUT-003 | A failed call gets an `error_kind` (`OutboundErrorKind`) classified from the exception class and transport text; the text itself is never stored |
| FR-OUT-004 | `HttpLogHttpClientWrapper` wraps clients built in code that the compiler pass cannot see; with outbound capture disabled it returns the client unchanged |
| FR-MAIL-001 | With `mailer` capture enabled, one `mailer` row per send attempt records transport scheme and host, outcome and Message-ID of a sent mail; envelope, recipients, subject, headers, body and credentials are never read |

### Named inbound channels and tenant context (`FR-CHAN-*`, `FR-CTX-*`)

| ID | Requirement |
| --- | --- |
| FR-CHAN-001 | `channels` entries match requests by path and route include / exclude lists, in config order; the first match records the request |
| FR-CHAN-002 | A named-channel row is an allowlist (route path template, method, scheme, host, status, duration, response size and type, client IP and user per config, guarded context); never headers, query string or bodies; errors always, successes by `sampling_rate` |
| FR-CHAN-003 | Route templates come from a map cached under `kernel.cache_dir` (optionally warmed at `cache:warmup`); an unknown route stores `{unrouted}` |
| FR-CHAN-004 | The client IP of a channel row is kept, truncated to its network (IPv4 /24, IPv6 /48) or dropped, per channel |
| FR-CHAN-005 | Hosts name the credential and tenant of a request with services tagged `nowo_http_log.request_context_resolver` (first non-null result wins); every returned value must be an opaque key within its length limit and must not equal or contain a credential the request carried, else it is dropped with one warning |
| FR-CHAN-006 | Resolvers receive a framework-free `HttpRequestContext` (allowlisted headers and scalar attributes, secret-looking names dropped, raw attribute values held outside the object); the Symfony-typed resolver interface is deprecated and removed in 2.0 |
| FR-CTX-001 | `HttpLogContext` keeps a stack of tenant / project / message-id frames that outbound calls and mails read; frames are always popped and `kernel.reset` empties the stack |
| FR-CTX-002 | The Messenger middleware stamps a message once on dispatch (stable id, optional tenant / project) and pushes a frame while a worker handles it |

### Usage capture, read API and rollup (`FR-USE-*`, `FR-ROLL-*`, `FR-EVT-*`)

| ID | Requirement |
| --- | --- |
| FR-USE-001 | Usage captures carry only allowlisted columns, each validated by charset and length; an invalid value becomes NULL (or the documented placeholder), never truncated |
| FR-USE-002 | Captures are buffered and written at the request, command or message boundary (or after a fixed number), directly by DBAL `INSERT` or through Messenger when `async`; outbound capture is suspended while the buffer writes and the shared EntityManager is never flushed |
| FR-USE-003 | `HttpLogUsageReaderInterface` reads daily usage, per-call pages (keyset cursor), and last-used per credential; every query carries an explicit tenant or platform scope and days are UTC |
| FR-ROLL-001 | With `rollup.enabled`, two rollup tables (`daily`, `event_seen`) are added to the entry EntityManager's schema; other hosts see no schema diff |
| FR-ROLL-002 | Buckets are upserted per platform (MySQL / MariaDB, PostgreSQL, SQLite) exactly once together with their row or ledger entry; purge and erasure fold buckets |
| FR-ROLL-003 | `RollupBackfillCommand` builds buckets from per-call rows only for days with none, never for today or before the cutoff |
| FR-EVT-001 | `HttpLogEntryRecordedEvent` is dispatched after a committed web or usage row, with ids and scalars only |

### Privacy (`FR-PRV-*`)

| ID | Requirement |
| --- | --- |
| FR-PRV-001 | Export of one subject's entries (user identifier or client IP) matches exactly and is bounded by `export_limit`; client IP windows of a user are exposed for other bundles |
| FR-PRV-002 | Erasure of one subject opens its own transaction (or joins the caller's) and dispatches `HttpLogPersonalDataErasedEvent` only after its own commit |
| FR-PRV-003 | Subjects erased during a request are remembered so capture at `kernel.terminate` does not write them again |
| FR-PRV-004 | Usage data can be erased by credential or by tenant, in batches of `retention.purge_batch_size` |
| FR-PRV-005 | With UserKit installed and `personal_data.enabled`, an export section and an eraser map a user to subjects through `HttpLogPersonalDataSubjectResolverInterface` (default: original identifier, else current identifier) |

### Database exactness, admin list and export storage (`FR-DB-*`, `FR-ADM-002`, `FR-EXP-002`, `FR-SEC-*`)

| ID | Requirement |
| --- | --- |
| FR-DB-001 | On MySQL / MariaDB the identifier columns of the entry table use a binary NO PAD collation so filters and indexes do not fold case, accents or trailing spaces |
| FR-DB-002 | With `web_ui.filter_match: prefix`, a `path_prefix` column and index are mapped; other hosts keep their schema |
| FR-DB-003 | Usage columns and indexes are mapped only when `outbound`, `mailer` or a named channel is enabled |
| FR-ADM-002 | The admin list reads scalar projections and pages by keyset cursor (`?cursor=`) instead of `OFFSET` |
| FR-EXP-002 | Background exports are written to a private directory under random names, offered only to users allowed to export (and to the channels they may see), and deleted after `export.ttl_hours` |
| FR-UI-004 | When the access checker implements `HttpLogChannelAccessCheckerInterface`, list, entry, export and purge leave out denied channels (a denied entry gives the same 404 as a missing one) |
| FR-SEC-001 | The container build fails when the admin UI is enabled without form CSRF protection |

### Internationalization (`FR-I18N-*`)

| ID | Requirement |
| --- | --- |
| FR-I18N-002 | Ship catalogues for en, es, fr, de, it, nl, pt with key parity |
| FR-I18N-003 | Use translation domain `NowoHttpLogBundle`; app overrides take priority |

## Non-goals

- OpenTelemetry / distributed trace export
- Real-time WebSocket log streaming
- Multi-database sharding of log storage

## Success criteria

| ID | Criterion |
| --- | --- |
| SC-01 | Unit tests cover classifier, redactor, capture policy, subscriber, configuration, access checker, Twig compiler pass |
| SC-02 | Demo returns HTTP 200 and admin UI is reachable with credentials |
| SC-03 | `code-inventory.md` lists every file under `src/` (132 units) |
| SC-04 | Integrator docs describe full YAML tree and security defaults |
| SC-05 | Bundle is viable under FrankenPHP worker mode without kernel reset (scenario B); see [docs/FRANKENPHP-WORKER-AUDIT.md](../../docs/FRANKENPHP-WORKER-AUDIT.md) |

## Validation

```bash
make qa
make validate-translations
make test-coverage
make demo-smoke
composer phpstan
```

## Related requirements

See `docs/SPEC-DRIVEN-DEVELOPMENT.md` for `REQ-*` traceability (REQ-UI-002, REQ-TEST-011, REQ-TWIG-001, REQ-MAKE-004, …).
