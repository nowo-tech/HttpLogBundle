# Configuration

All options live under the root key `nowo_http_log` (alias `nowo_http_log`).

## Table of contents

- [Full YAML tree](#full-yaml-tree)
- [Top-level options](#top-level-options)
- [capture](#capture)
- [redaction](#redaction)
- [retention](#retention)
- [doctrine](#doctrine)
- [export](#export)
- [web_ui](#web_ui)
  - [Form profile (FormKit)](#form-profile-formkit)
  - [Deprecated 1.x chrome keys](#deprecated-1x-chrome-keys)
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
    ignore_default_path_prefixes: true    # Also skip /_nowo/ (HotReload), /sw.js, /manifest.webmanifest (PWA), /_wdt/, /_profiler/
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
        purge_batch_size: 1000            # Rows deleted per statement (keyset batches by id)
    doctrine:
        table_prefix: ''                  # Prefix for nowo_http_log_entry + index names (REQ-ORM-002)
    export:
        formats:
            - csv
            - json
        max_sync_rows: 1000               # Max rows for synchronous UI/CLI export
        directory: '%kernel.project_dir%/var/http-log/exports' # Private dir of background exports
        ttl_hours: 24                     # Background exports are deleted after this many hours
    web_ui:
        enabled: true
        path_prefix: /admin/http-log      # Route prefix of the admin UI; must start with /
        layout_template: null             # null = UiKit admin layout (nowo_ui_kit.layouts.admin)
        page_size: 50
        templates:                        # web_ui.templates.<page>
            index: '@NowoHttpLogBundle/admin/index.html.twig'
            show: '@NowoHttpLogBundle/admin/show.html.twig'
        # Deprecated since 1.8 (removed in 2.0), see "Deprecated 1.x chrome keys":
        # icon_set, css_framework, templates.filter, templates.pagination, css_classes, attributes,
        # ui_kit_stylesheet, header_actions_in_layout, confirm_attributes, ui_kit_row_actions
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
| `ignore_path_prefixes` | list | `[/admin/http-log]` | Path prefixes excluded from capture (plain `str_starts_with`). `web_ui.path_prefix` is always excluded as well while the web UI is enabled. Setting this list replaces the default `[/admin/http-log]` (Symfony list semantics) but never removes the kit defaults below. |
| `ignore_default_path_prefixes` | bool | `true` | Merges the kit-internal prefixes `/_nowo/` (HotReloadBundle client / shared worker), `/sw.js` and `/manifest.webmanifest` (PwaBundle) and, since 1.9, the Symfony WebProfiler paths `/_wdt/` and `/_profiler/` into `ignore_path_prefixes` (host entries first, duplicates removed). `false` uses only your `ignore_path_prefixes`. Since 1.7.0. |
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
| `purge_batch_size` | `1000` | Rows deleted per statement by every purge (retention / `--days`, admin UI, `PurgeHttpLogMessage`). The retention purge walks ids in keyset batches (`id > :afterId ORDER BY id LIMIT n`, then `DELETE … WHERE id IN (…)`) until a short batch, so a large log is never deleted in one table-locking, binlog-filling statement. Must be `>= 1`. Since 1.7.0. |

Use `nowo:http-log:purge` or the admin UI to purge.

## doctrine

Since 1.10 (REQ-ORM-002). Namespace the bundle-owned table when several applications share one database:

```yaml
nowo_http_log:
    doctrine:
        table_prefix: 'app_'   # default '' (no prefix); letters, digits and underscores only
```

| Default | With `table_prefix: 'app_'` |
|---------|-----------------------------|
| table `nowo_http_log_entry` | `app_nowo_http_log_entry` |
| indexes `idx_http_log_*` | `app_idx_http_log_*` |

`Nowo\HttpLogBundle\Doctrine\TablePrefixListener` (Doctrine `loadClassMetadata`, registered only when the prefix is not empty) rewrites the `HttpLogEntry` mapping only. Setting a prefix on an existing install renames the table: generate a migration (`doctrine:migrations:diff`) and rename instead of dropping.

## export

| Key | Default | Description |
| --- | --- | --- |
| `formats` | `[csv, json]` | Allowed export formats (`csv`, `json`; at least one). The admin UI only shows buttons for enabled formats and answers **400** to others; `nowo:http-log:export` exits with `INVALID` for a disabled format. |
| `max_sync_rows` | `1000` | Row cap for synchronous exports; larger jobs should use async Messenger. |
| `directory` | `%kernel.project_dir%/var/http-log/exports` | Private directory of background exports (created `0700`, files `0600`, random names). Keep it out of the web root and off shared temp dirs. |
| `ttl_hours` | `24` | Hours a finished background export stays downloadable; expired files are deleted after each export job and when the admin list is opened. |

## web_ui

The admin pages follow the UiKit 2.1 page contract (REQ-UI-003): the list renders the `@NowoUiKit/page/list`
scaffold and the entry page `@NowoUiKit/page/show`, inside the layout configured once for every bundle
(`nowo_ui_kit.layouts.admin`). CSS stack, icon set, row-action display and every class / attribute come from
`nowo_ui_kit` (`css_framework`, `icon_set`, `row_actions_display`, `theme`). Page list and data blocks:
[TEMPLATES.md](TEMPLATES.md).

| Key | Default | Description |
| --- | --- | --- |
| `enabled` | `true` | Registers admin routes and Twig templates. |
| `path_prefix` | `/admin/http-log` | Route prefix of the admin UI (list, detail, export, purge, delete), applied by the router through the `nowo_http_log.web_ui.path_prefix` parameter. A trailing `/` is removed; `/` alone and `%` are rejected. Update your `access_control` rule when you change it. The recorder always skips this prefix (segment-aware: `/ops/logs` and `/ops/logs/…`, not `/ops/logsearch`) while the web UI is enabled, so you do not need to add it to `ignore_path_prefixes`. If you import the routes under an extra `prefix:`, admin requests are still skipped by the default `ignore_routes` entry `nowo_http_log_*`; keep it or add the full path to `ignore_path_prefixes`. |
| `layout_template` | `null` | Layout the scaffolds extend for this bundle only (`page.layout`). `null` = `nowo_ui_kit.layouts.admin`. It must render the UiKit canonical blocks (`nowo_ui_document_title`, `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, …). The 1.x value `@NowoHttpLogBundle/layout.html.twig` (removed) is read as `null`. |
| `page_size` | `50` | Pagination size on index. |
| `templates.index` / `templates.show` | bundle pages | Page templates the controller renders. Point them at a host template that extends `@!NowoHttpLogBundle/admin/<page>.html.twig` and overrides one data block. |

```yaml
nowo_ui_kit:
    layouts:
        admin: 'layouts/admin.html.twig'   # once for every UiKit-based bundle
    theme:
        bundles:
            NowoHttpLogBundle:             # exceptions for this bundle only
                classes: { table: 'data-table data-table--dense' }
```

When `enabled: false`, the admin controller is removed from the container.

### Form profile (FormKit)

Every admin form is a FormKit Type (REQ-TWIG-005 rule 11): `HttpLogFilterType` and the CSRF-only actions
(`HttpLogExportType`, `HttpLogPurgeType`, `HttpLogDeleteType`, all extending `AbstractHttpLogActionType`) carry
`#[FormKitConfig('http_log')]` + `FormOptionsTrait`. The bundle prepends the profile
**`nowo_form_kit.profiles.http_log`** (translation domain `NowoHttpLogBundle`, `convention_requires_translation: true`,
UiKit / Bootstrap field classes) unless the host defines it. To change field classes or row attributes, define the
profile yourself (nothing is seeded then, so set every key you need):

```yaml
# config/packages/nowo_form_kit.yaml
nowo_form_kit:
    profiles:
        http_log:
            alias: http_log
            translation_domain: NowoHttpLogBundle
            convention_requires_translation: true
            defaults:
                attr: { class: 'nowo-ui-input form-control' }
            field_types:
                choice: { attr: { class: 'form-select' } }
```

The action forms only carry hidden fields (`_token`, `format`, export criteria): they are added without profile
defaults, so no field class reaches the hidden inputs. The admin templates set no `form_theme`: the host
`twig.form_themes` applies.

### Deprecated 1.x chrome keys

Deprecated in 1.8 and removed in 2.0. Setting one triggers a Symfony deprecation. The class / attribute / partial
keys are **bridged**: values that differ from the 1.x defaults are prepended into
`nowo_ui_kit.theme.bundles.NowoHttpLogBundle` (so your own `nowo_ui_kit` config still wins). Mapping tables:
[UPGRADING.md — To 1.8.0](UPGRADING.md#to-180).

| Key | Replacement |
| --- | --- |
| `css_framework` | `nowo_ui_kit.css_framework` (still seeded into UiKit when set here and not there) |
| `icon_set` | `nowo_ui_kit.icon_set` (still seeded into UiKit when set here and not there) |
| `templates.filter` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.partials.list_filters` (bridged) |
| `templates.pagination` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.partials.pagination` (bridged) |
| `css_classes.*` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.classes.<slot>` (bridged per slot) |
| `attributes.*` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.attributes.<slot>` (bridged); `results` / `show` go on the page root |
| `ui_kit_stylesheet` | no effect: the admin layout loads `nowo-ui.css` |
| `header_actions_in_layout` | no effect: header actions are part of the page header (`nowo_ui_page_header` block) |
| `confirm_attributes` | no effect: purge / delete open the UiKit confirm dialog (no inline `confirm()`) |
| `ui_kit_row_actions` | no effect: row actions always render through the UiKit `row_actions` partial |

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
