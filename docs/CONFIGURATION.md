# Configuration

All options live under the root key `nowo_http_log` (alias `nowo_http_log`).

## Table of contents

- [Full YAML tree](#full-yaml-tree)
- [Top-level options](#top-level-options)
- [capture](#capture)
- [redaction](#redaction)
- [retention](#retention)
- [export](#export)
- [web_ui](#web_ui)
- [security](#security)
- [Messenger retry and failure transport](#messenger-retry-and-failure-transport)
- [Environment-specific examples](#environment-specific-examples)

## Full YAML tree

```yaml
nowo_http_log:
    enabled: true                         # Master switch for capture subscriber
    environments: []                      # Empty = all environments; else list e.g. [dev, prod]
    async: true                           # Persist via Messenger when true
    sampling_rate: 1.0                    # 0.0–1.0 fraction of requests to log
    track_sub_requests: false             # Log sub-requests (ESI, internal forwards)
    ignore_routes:                        # fnmatch patterns on _route
        - '_wdt'
        - '_profiler'
        - 'web_profiler*'
        - 'nowo_http_log_*'
    ignore_path_prefixes:                 # Skip paths starting with these prefixes
        - '/admin/http-log'
    capture:
        request_headers: true
        request_body: false               # Off by default (privacy / size)
        response_headers: true
        client_ip: true
        user: true                        # Authenticated user identifier when available
        max_body_bytes: 65536             # Truncate stored bodies; 0 stores empty string
        response_body_by_type:
            html: false
            json: true
            soap: true
            xml: true
            text: false
            binary: false
            other: false
    redaction:
        headers:
            - Authorization
            - Cookie
            - Set-Cookie
            - X-Api-Key
        query_params:
            - password
            - token
            - api_key
        json_paths:
            - '*.password'
            - '*.token'
    retention:
        days: 30                          # null = keep forever
    export:
        formats:
            - csv
            - json
        max_sync_rows: 1000               # Max rows for synchronous UI/CLI export
    web_ui:
        enabled: true
        path_prefix: /admin/http-log      # Must start with /
        layout_template: '@NowoHttpLogBundle/layout.html.twig'
        css_framework: bootstrap5         # bootstrap5 | tailwind | foundation | custom
        page_size: 50
    security:
        access_roles:
            - ROLE_ADMIN
        access_checker: null                # Custom service id implementing HttpLogAccessCheckerInterface
        allow_unauthenticated: false      # true only for trusted internal networks
```

## Top-level options

| Key | Type | Default | Description |
| --- | --- | --- | --- |
| `enabled` | bool | `true` | When `false`, the subscriber does not record entries. |
| `environments` | list | `[]` | Restrict capture to named kernel environments. Empty means all. |
| `async` | bool | `true` | Dispatch `PersistHttpLogMessage` instead of flushing inline. |
| `sampling_rate` | float | `1.0` | Random sampling; `1.0` logs every eligible request. |
| `track_sub_requests` | bool | `false` | Include sub-requests in capture. |
| `ignore_routes` | list | see YAML | Route name patterns skipped (`fnmatch`). |
| `ignore_path_prefixes` | list | `[/admin/http-log]` | Path prefixes excluded from capture. |

## capture

Controls **what** is stored for each request/response pair.

| Key | Default | Notes |
| --- | --- | --- |
| `request_headers` | `true` | Stored as JSON; redacted per `redaction.headers`. |
| `request_body` | `false` | Enable only when needed; subject to `max_body_bytes`. |
| `response_headers` | `true` | Same redaction as request headers. |
| `client_ip` | `true` | From Symfony Request. |
| `user` | `true` | Security user identifier when authenticated. |
| `max_body_bytes` | `65536` | Bodies longer than this are truncated. `0` stores `''`. |
| `response_body_by_type.*` | see YAML | Per `BodyContentType` enum value (`html`, `json`, …). |

Content type detection uses the `Content-Type` header and optional body sniffing (see `ContentTypeClassifier`).

## redaction

Applied **before** persistence.

| Key | Purpose |
| --- | --- |
| `headers` | Header names replaced with `[REDACTED]` (case-insensitive). |
| `query_params` | Query keys replaced with `[REDACTED]`. |
| `json_paths` | JSON object keys; supports `*.suffix` wildcard for nested keys. |

Non-JSON bodies are not structurally redacted.

## retention

| Key | Default | Description |
| --- | --- | --- |
| `days` | `30` | Entries older than N days are eligible for purge. `null` disables automatic retention cutoff. |

Use `nowo:http-log:purge` or the admin UI to purge.

## export

| Key | Default | Description |
| --- | --- | --- |
| `formats` | `[csv, json]` | Allowed export formats in UI and CLI. |
| `max_sync_rows` | `1000` | Row cap for synchronous exports; larger jobs should use async Messenger. |

## web_ui

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | Registers admin routes and Twig templates. |
| `path_prefix` | `/admin/http-log` | Base path for list, detail, export, purge actions. |
| `layout_template` | bundle layout | Root Twig layout (global `nowo_http_log_layout_template`) extended by `admin/base.html.twig`. Set to your app layout or a one-file bridge. |
| `css_framework` | `bootstrap5` | UI macro styling (`CssFramework` enum). Also seeds `nowo_ui_kit.css_framework` when the host has not configured UiKit. |
| `page_size` | `50` | Pagination size on index. |

Admin pages extend `@NowoHttpLogBundle/admin/base.html.twig`, which extends `web_ui.layout_template` and stacks `stylesheets` / `javascripts` with `{{ parent() }}` (REQ-UI-001). The base shell loads `asset('css/nowo-ui.css', 'nowo_ui_kit')` and uses UiKit macros (`ui.btn`, `ui.flash`, …). Prefer pointing `layout_template` at your project layout (or a thin bridge that maps `nowo_ui_content` into your `body` block) instead of copying list/detail templates. The default `layout.html.twig` is a full HTML document (CDN Bootstrap; no `parent()`). Host layouts should expose `stylesheets` and `javascripts` blocks so the base shell can call `{{ parent() }}`.

```yaml
nowo_http_log:
    web_ui:
        layout_template: 'base.html.twig'
        css_framework: bootstrap5
```

When `enabled: false`, the admin controller is removed from the container.

## security

| Key | Default | Description |
| --- | --- | --- |
| `access_roles` | `[ROLE_ADMIN]` | Any matching role grants admin, export, and purge (REQ-UI-002). |
| `access_checker` | `null` | Optional service id for fine-grained checks (`HttpLogAccessCheckerInterface`). |
| `allow_unauthenticated` | `false` | When `true`, skips Symfony Security requirement for the UI (not recommended in production). |

## Messenger retry and failure transport

When `async: true` (default), capture dispatches `PersistHttpLogMessage`; export and purge can use `ExportHttpLogMessage` and `PurgeHttpLogMessage`. Handlers are idempotent for redelivered persist/export jobs; purge deletes by retention cutoff.

**Production recommendation:** route Http Log messages to a dedicated async transport with retries and a failure transport so transient DB or filesystem errors do not silently drop audit data.

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed
        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2
            failed: 'doctrine://default?queue_name=failed'
        routing:
            'Nowo\HttpLogBundle\Message\PersistHttpLogMessage': async
            'Nowo\HttpLogBundle\Message\ExportHttpLogMessage': async
            'Nowo\HttpLogBundle\Message\PurgeHttpLogMessage': async
```

Run a worker on both transports: `php bin/console messenger:consume async failed -vv`. Inspect the `failed` queue after repeated handler errors; messages contain redacted capture arrays — treat failed exports like any other log export (filesystem permissions, no public sharing).

Set `nowo_http_log.async: false` only when you accept inline persistence on `kernel.terminate` (simpler demos; not ideal under high load).

## Environment-specific examples

**Production — JSON/XML API only, no request bodies:**

```yaml
nowo_http_log:
    environments: [prod]
    sampling_rate: 0.1
    capture:
        request_body: false
        response_body_by_type:
            html: false
            json: true
            xml: true
```

**Development — full capture for debugging:**

```yaml
when@dev:
    nowo_http_log:
        capture:
            request_body: true
            response_body_by_type:
                html: true
                json: true
                text: true
```

See also [USAGE.md](USAGE.md) and [SECURITY.md](SECURITY.md).
