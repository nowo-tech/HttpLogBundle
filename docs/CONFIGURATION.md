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
  - [Admin chrome hooks (1.3.0)](#admin-chrome-hooks-130)
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
    ignore_unrouted: false                # true: skip requests no route matched (scanner 404s such as /wp-login.php)
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
            - _password
            - token
            - _token
            - _csrf_token
            - api_key
        json_paths:
            - '*.password'
            - '*._password'
            - '*.token'
            - '*._token'
            - '*._csrf_token'
    retention:
        days: 30                          # null = keep forever
    export:
        formats:
            - csv
            - json
        max_sync_rows: 1000               # Max rows for synchronous UI/CLI export
    web_ui:
        enabled: true
        path_prefix: /admin/http-log      # Route prefix of the admin UI; must start with /
        icon_set: bootstrap-icons         # Seeds nowo_ui_kit.icon_set when UiKit is installed and unset
        layout_template: '@NowoHttpLogBundle/layout.html.twig'
        css_framework: bootstrap5         # bootstrap5 | tailwind | foundation | custom
        page_size: 50
        # Admin chrome hooks (1.3.0) — defaults reproduce the 1.2.0 markup
        templates:
            index: '@NowoHttpLogBundle/admin/index.html.twig'
            show: '@NowoHttpLogBundle/admin/show.html.twig'
            filter: '@NowoHttpLogBundle/admin/_filter.html.twig'
            pagination: '@NowoHttpLogBundle/admin/_pagination.html.twig'
        css_classes: {}                   # see "Admin chrome hooks" for every key and default
        attributes: {}                    # e.g. results: { data-testid: http-log-results }
        ui_kit_stylesheet: true
        header_actions_in_layout: false
        confirm_attributes: {}            # e.g. data-confirm-message: '{message}'
        ui_kit_row_actions: false
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
| `ignore_path_prefixes` | list | `[/admin/http-log]` | Path prefixes excluded from capture. `web_ui.path_prefix` is always excluded as well while the web UI is enabled. |
| `ignore_unrouted` | bool | `false` | When `true`, skip requests no route matched (no `_route`, e.g. scanner 404s on `/wp-login.php`, `/.env`). |

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

Bodies are redacted **in full, before** `max_body_bytes` truncation, according to their `Content-Type`:

- **JSON** (`application/json`, `*+json`, or a body starting with `{` / `[`): keys matching `json_paths`. A JSON-looking body that cannot be decoded is stored as `[REDACTED-UNPARSEABLE]` instead of its raw content.
- **`application/x-www-form-urlencoded`** and **`multipart/form-data` text fields**: field names (any segment of `a[b][c]`) matching `query_params` or the leaf key of `json_paths` (case-insensitive). Multipart file parts are kept; multipart without a boundary is stored as `[REDACTED-UNPARSEABLE]`.
- Other bodies (HTML, plain text, XML, binary) are not structurally redacted.

Body redaction is skipped entirely when both `query_params` and `json_paths` are empty.

## retention

| Key | Default | Description |
| --- | --- | --- |
| `days` | `30` | Entries older than N days are eligible for purge. `null` disables automatic retention cutoff. |

Use `nowo:http-log:purge` or the admin UI to purge.

## export

| Key | Default | Description |
| --- | --- | --- |
| `formats` | `[csv, json]` | Allowed export formats (`csv`, `json`; at least one). The admin UI only shows buttons for enabled formats and answers **400** to others; `nowo:http-log:export` exits with `INVALID` for a disabled format. |
| `max_sync_rows` | `1000` | Row cap for synchronous exports; larger jobs should use async Messenger. |

## web_ui

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | Registers admin routes and Twig templates. |
| `path_prefix` | `/admin/http-log` | Route prefix of the admin UI (list, detail, export, purge, delete), applied by the router through the `nowo_http_log.web_ui.path_prefix` parameter. A trailing `/` is removed; `/` alone and `%` are rejected. Update your `access_control` rule when you change it. The recorder always skips this prefix (segment-aware: `/ops/logs` and `/ops/logs/…`, not `/ops/logsearch`) while the web UI is enabled, so you do not need to add it to `ignore_path_prefixes`. If you import the routes under an extra `prefix:`, admin requests are still skipped by the default `ignore_routes` entry `nowo_http_log_*`; keep it or add the full path to `ignore_path_prefixes`. |
| `icon_set` | `bootstrap-icons` | Seeds `nowo_ui_kit.icon_set` when NowoUiKitBundle is installed and the host did not set it. |
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

### Admin chrome hooks (1.3.0)

Restyle the admin pages without forking them (spec [002](../specs/002-admin-chrome-hooks/spec.md); usage and the
Beacon example in [USAGE.md — Customising the admin chrome](USAGE.md#customising-the-admin-chrome)). The controller
passes the resolved values to Twig as `nowo_http_log_ui`.

| Key | Default | Description |
| --- | --- | --- |
| `templates.index` | `@NowoHttpLogBundle/admin/index.html.twig` | Template the list action renders. |
| `templates.show` | `@NowoHttpLogBundle/admin/show.html.twig` | Template the detail action renders. |
| `templates.filter` | `@NowoHttpLogBundle/admin/_filter.html.twig` | Filter partial included by the list page. |
| `templates.pagination` | `@NowoHttpLogBundle/admin/_pagination.html.twig` | Pagination partial (UiKit-compatible contract, see USAGE). |
| `css_classes.*` | 1.2.0 classes | Class map below. Unknown keys are rejected. |
| `attributes` | `{}` | Extra HTML attributes per element: `filter`, `filter_form`, `results`, `toolbar`, `table_wrapper`, `table`, `show`, `details`, `delete_form`. Dashed names (`data-testid`) are kept. |
| `ui_kit_stylesheet` | `true` | `admin/base.html.twig` appends UiKit `nowo-ui.css`. Set `false` when your layout ships the admin CSS. |
| `header_actions_in_layout` | `false` | Pages skip the inline export / purge buttons and back link; the layout renders `{{ block('nowo_http_log_header_actions') }}` (the bundle layout does it in its header). |
| `confirm_attributes` | `{}` | Replaces the inline `onsubmit="return confirm(…)"` on purge / delete forms (CSP). `{message}` is replaced by the translated prompt. |
| `ui_kit_row_actions` | `false` | List row actions via `@NowoUiKitBundle/partials/_row_actions.html.twig` (honours `nowo_ui_kit.row_actions_display`; UiKit >= 1.6). |

`css_classes` keys (`null` = UiKit macro for `css_framework`; `''` = no `class` attribute, and no wrapper element
for `results` / `show`):

| Key | Default | Element |
| --- | --- | --- |
| `filter` | `nowo-ui-filter card mb-4` | Filter panel |
| `filter_body` | `card-body` | Filter panel body |
| `filter_form` | `nowo-ui-filter-form row g-2 align-items-end` | Filter `<form>` |
| `btn_filter` | `null` (`ui.btn('primary')`) | Filter submit (`nowo-ui-btn-filter` always appended) |
| `results` | `''` | Optional wrapper around toolbar + table + pagination |
| `toolbar` | `nowo-ui-toolbar d-flex justify-content-between align-items-center mb-3` | Toolbar row |
| `summary` | `nowo-ui-summary mb-0` | "N entries" |
| `actions` | `nowo-ui-actions` | Header actions container |
| `action_form` | `d-inline` | Export / purge forms |
| `action_form_gap` | `ms-1` | Added to the 2nd and 3rd action forms |
| `btn_export` | `null` (`ui.btn('secondary', 'sm')`) | Export buttons (`nowo-ui-btn-export`) |
| `btn_purge` | `null` (`ui.btn('danger', 'sm')`) | Purge button (`nowo-ui-btn-purge`) |
| `table_wrapper` | `nowo-ui-table-wrap table-responsive` | Table wrapper |
| `table` | `table table-striped table-sm nowo-ui-table` | `<table>` |
| `table_head_row` / `table_head_cell` | `''` | `<thead><tr>` / `<th>` |
| `table_row` / `table_cell` | `''` | `<tbody><tr>` / `<td>` |
| `method_badge` | `badge bg-secondary` | HTTP method badge |
| `path_cell` | `text-truncate` | Path `<td>` (added to `table_cell`) |
| `row_actions` | `null` (`ui.row_actions`) | Row actions container |
| `btn_view` | `null` (`ui.btn('secondary', 'sm')`) | View link (`nowo-ui-btn-view`) |
| `empty_cell` | `text-muted` | Empty-state cell |
| `pagination` / `pagination_list` | `nowo-ui-pagination mt-3` / `pagination` | Default pagination `<nav>` / `<ul>` |
| `page_item` / `page_link` | `page-item` / `page-link` | Default pagination items |
| `show` | `''` | Optional wrapper around the detail content |
| `back` / `back_link` | `nowo-ui-back mb-3` / `''` | Back link paragraph / anchor |
| `details` | `row nowo-ui-details` | Detail `<dl>` |
| `detail_term` / `detail_value` | `col-sm-3` / `col-sm-9` | `<dt>` / `<dd>` |
| `section_title` | `h5 nowo-ui-section-title` | Section `<h2>` |
| `code` | `nowo-ui-code bg-light p-3` | Headers / body `<pre>` |
| `delete_form` | `mt-4` | Delete `<form>` |
| `btn_delete` | `null` (`ui.btn('danger')`) | Delete button (`nowo-ui-btn-delete`) |

Write a literal `%` in YAML values as `%%` (Symfony container parameters).

## security

| Key | Default | Description |
| --- | --- | --- |
| `access_roles` | `[ROLE_ADMIN]` | Any matching role grants admin, export, and purge (REQ-UI-002). An empty list grants **nobody** (fail closed); use `allow_unauthenticated` or a custom `access_checker` to open the UI deliberately. |
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
