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
| FR-CAP-002 | Store client `X-Request-Id` (truncated to 64 chars) or generate a random hex id |
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
| FR-TWIG-001 | Application Twig overrides under `templates/bundles/NowoHttpLogBundle/` take precedence over bundle templates |

### Export & purge (`FR-EXP-*`, `FR-PUR-*`)

| ID | Requirement |
| --- | --- |
| FR-EXP-001 | Export filtered rows to CSV or JSON (UI, CLI, or async Messenger) up to configured sync limits |
| FR-PUR-001 | Purge entries older than `retention.days` via CLI, UI, or async handler |

### Filtering (`FR-IGN-*`)

| ID | Requirement |
| --- | --- |
| FR-IGN-001 | Skip capture when `ignore_routes` or `ignore_path_prefixes` match |

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

### Messenger (`FR-MSG-*`)

| ID | Requirement |
| --- | --- |
| FR-MSG-001 | Async handlers for persist, export, and purge with documented retry/failure transport expectations |

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
| SC-03 | `code-inventory.md` lists every file under `src/` (43 units) |
| SC-04 | Integrator docs describe full YAML tree and security defaults |

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
