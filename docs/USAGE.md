# Usage

How to capture HTTP traffic, use the admin UI, export, purge, and exclude routes.

## Table of contents

- [Automatic capture](#automatic-capture)
- [Body storage policy](#body-storage-policy)
- [Redaction](#redaction)
- [Admin UI](#admin-ui)
- [Twig template overrides](#twig-template-overrides)
- [Customising the admin chrome](#customising-the-admin-chrome)
- [Translation overrides](#translation-overrides)
- [Export](#export)
- [Purge and retention](#purge-and-retention)
- [Ignoring routes and paths](#ignoring-routes-and-paths)
- [CLI commands](#cli-commands)
- [Async processing](#async-processing)

## Automatic capture

Once installed and enabled, the bundle registers `HttpLogSubscriber` on:

- `kernel.request` — records start time and request id (`X-Request-Id` header or generated UUID).
- `kernel.terminate` — builds the log entry and persists (sync or async).

Each stored row includes method, path, route name, status code, duration (ms), client IP, user identifier, headers, and optional bodies.

Disable globally:

```yaml
nowo_http_log:
    enabled: false
```

Restrict to specific environments:

```yaml
nowo_http_log:
    environments: [prod, staging]
```

Sample traffic:

```yaml
nowo_http_log:
    sampling_rate: 0.25
```

## Body storage policy

Request bodies are **off by default**. Enable explicitly:

```yaml
nowo_http_log:
    capture:
        request_body: true
        max_body_bytes: 32768
```

Response bodies are stored per content type:

```yaml
nowo_http_log:
    capture:
        response_body_by_type:
            json: true
            html: false
            binary: false
```

Classification uses the `Content-Type` header and body sniffing for SOAP, JSON, HTML, and XML when the header is missing.

## Redaction

Sensitive data is redacted before storage. Customize lists in [CONFIGURATION.md](CONFIGURATION.md#redaction).

Example — redact `Authorization` and JSON `*.secret`:

```yaml
nowo_http_log:
    redaction:
        headers:
            - Authorization
        json_paths:
            - '*.secret'
```

Redacted values appear as `[REDACTED]`.

## Admin UI

Default URL: **`/admin/http-log`** (configurable via `web_ui.path_prefix`).

Features:

- Paginated list with filters (method, status, route, date range, free text)
- Detail view with headers and bodies
- Export and purge actions (subject to access checker)

Access requires Symfony Security unless `security.allow_unauthenticated: true`. Default roles: `ROLE_ADMIN` (REQ-UI-002).

Custom access logic — implement `HttpLogAccessCheckerInterface` and set:

```yaml
nowo_http_log:
    security:
        access_checker: App\Security\MyHttpLogAccessChecker
```

## Twig template overrides

Application templates under `templates/bundles/NowoHttpLogBundle/` **always win** over the bundle copy for the same relative path (REQ-TWIG-001). The bundle registers its views **after** Symfony’s override directory so host files take precedence.

**Freeze rule:** once you copy a template into your app, that path is **frozen** — vendor updates to the same file will not apply until you delete or manually merge your override.

**Procedure**

1. Pick the `<subpath>` from the table below (path relative to the bundle views root).
2. Create `templates/bundles/NowoHttpLogBundle/<subpath>.html.twig` in your application.
3. Clear the cache if needed: `php bin/console cache:clear`.
4. Prefer config (`web_ui.layout_template`, `css_framework`, the 1.3.0 chrome hooks — see [Customising the admin chrome](#customising-the-admin-chrome)) or block-only overrides before forking full pages.

Point `web_ui.layout_template` at your project layout to embed admin pages in host chrome; pages go through `admin/base.html.twig`, which stacks host `stylesheets` / `javascripts` via `{{ parent() }}` and loads UiKit CSS/macros (REQ-UI-001 / REQ-UI-001-kit). See [CONFIGURATION.md — web_ui](CONFIGURATION.md#web_ui).

| Subpath | Purpose |
| --- | --- |
| `layout.html.twig` | Default demo full HTML root (`web_ui.layout_template` default; CDN Bootstrap) |
| `admin/base.html.twig` | Intermediate shell admin pages extend (points at `web_ui.layout_template`, stacks assets with `parent()`) |
| `admin/index.html.twig` | Paginated log list, export/purge actions |
| `admin/show.html.twig` | Single entry detail view |
| `admin/_filter.html.twig` | Filter form partial on the index page (`web_ui.templates.filter`) |
| `admin/_pagination.html.twig` | List pagination partial (`web_ui.templates.pagination`, 1.3.0) |
| `admin/_macros.html.twig` | Chrome helpers `class_attr` / `attrs` (1.3.0) |

`admin/index.html.twig` and `admin/show.html.twig` submit actions with Symfony forms (`form_start()` / `form_end()`). If you override those templates, keep the flat `_token` field and the CSRF token ids `http_log_export`, `http_log_purge`, and `http_log_delete` intact.

To recover upstream UI after a full-file override, remove the app copy, clear cache, and re-apply only the customisations you still need.

## Customising the admin chrome

Since **1.3.0** (spec [002-admin-chrome-hooks](../specs/002-admin-chrome-hooks/spec.md)) you can restyle the admin
pages without copying them. Use the cheapest level that works:

1. **Config:** CSS class map, attribute map, presentation switches, partial / page template paths (`web_ui.*`, see [CONFIGURATION.md — Admin chrome](CONFIGURATION.md#admin-chrome-hooks-130)).
2. **Layout bridge:** `web_ui.layout_template` pointing at a one-file bridge into your layout (unchanged since 1.0).
3. **Thin fork:** a template that extends the bundle page and overrides one or two `nowo_http_log_*` blocks.
4. **Full fork:** only when the markup itself must differ everywhere (frozen; you lose vendor fixes).

With no `web_ui` chrome keys set, the rendered pages are whitespace-equivalent to 1.2.0 (golden-file tests).

### Blocks

| Template | Blocks (nesting with `>`) |
| --- | --- |
| `admin/index.html.twig` | `nowo_http_log_page_header` > `nowo_http_log_intro` (empty by default; the layout renders the title), `nowo_http_log_filter`, `nowo_http_log_toolbar` > `nowo_http_log_summary` + `nowo_http_log_header_actions` > `nowo_http_log_action_export_csv` / `nowo_http_log_action_export_json` / `nowo_http_log_action_purge`, `nowo_http_log_table` > `nowo_http_log_table_head` + `nowo_http_log_table_row` > `nowo_http_log_row_actions`, `nowo_http_log_empty_state`, `nowo_http_log_pagination` |
| `admin/show.html.twig` | `nowo_http_log_page_header` > `nowo_http_log_intro`, `nowo_http_log_header_actions` (back link), `nowo_http_log_details`, `nowo_http_log_detail_sections` > `nowo_http_log_section_query_params` / `_request_headers` / `_response_headers` / `_request_body` / `_response_body`, `nowo_http_log_delete` |
| `admin/_filter.html.twig` | `nowo_http_log_filter_panel` > `nowo_http_log_filter_fields` + `nowo_http_log_filter_actions` |

Inside the blocks the page exposes `_ui` (resolved chrome, also passed by the controller as `nowo_http_log_ui`),
`_cls` (class map), `_css_fw`, the `ui` UiKit macros and the controller variables (`entries`, `entry`, `criteria`,
the action forms…). `nowo_http_log_table_row` / `nowo_http_log_row_actions` run inside the loop (`entry`, `loop`).

**Thin fork.** The bundle registers its originals under `@!NowoHttpLogBundle`, so an override at the standard path
can extend the page it replaces:

```twig
{# templates/bundles/NowoHttpLogBundle/admin/index.html.twig #}
{% extends '@!NowoHttpLogBundle/admin/index.html.twig' %}

{% block nowo_http_log_empty_state %}
    <tr><td colspan="8" class="empty">{{ 'admin.index.empty'|trans({}, 'NowoHttpLogBundle') }}</td></tr>
{% endblock %}
```

Alternatively keep the fork outside `templates/bundles/` and select it with `web_ui.templates.index` (it then extends
`@NowoHttpLogBundle/admin/index.html.twig`). For the filter, set `web_ui.templates.filter` to a partial that extends
`@NowoHttpLogBundle/admin/_filter.html.twig` and overrides `nowo_http_log_filter_fields` / `nowo_http_log_filter_actions`.

### Header actions in the host layout

`web_ui.header_actions_in_layout: true` removes the export / purge buttons (list) and the back link (detail) from the
page body. The bundle `layout.html.twig` then renders them in its header; a host layout or bridge places them itself:

```twig
{# templates/admin/http_log_layout.html.twig (web_ui.layout_template) #}
{% extends 'admin/_layout.html.twig' %}

{% set page_header_actions %}
    {% if block('nowo_http_log_header_actions') is defined %}{{ block('nowo_http_log_header_actions') }}{% endif %}
{% endset %}

{% block body %}{% block nowo_ui_content %}{% endblock %}{% endblock %}
```

### Pagination partial contract

`web_ui.templates.pagination` is included with the page context plus `pagination {page, per_page, total, total_pages}`,
`pages`, `page_size`, `item_count`, `route` (`nowo_http_log_admin_index`), `route_params`, `query` (the active filters
in the shape the GET filter form reads — `{http_log_filter: {method: 'GET', createdFrom: '2026-08-01T09:30'}}`, empty
when unfiltered; merge `page` into it to build links), `nav_label` (`admin.index.title`), `nav_trans_domain` (`NowoHttpLogBundle`) and `framework`.
That matches `@NowoUiKitBundle/partials/_pagination.html.twig`, so you can point the option straight at it or at a
host adapter. The bundle default only renders when there is more than one page; your partial decides for itself.

### Example: Symfony Beacon

Beacon (Administration → HTTP log) forked all three pages in 1.2.0. Classification of the differences:

| Beacon fork | Differences vs 1.2.0 | Kind | Verdict with 1.3.0 |
| --- | --- | --- | --- |
| `admin/show.html.twig` | Skips `admin/base` (no `nowo-ui.css` re-append); back link moved to the page header (`kit_admin_header_actions`); `section.panel` wrapper + `data-testid="http-log-show"`; `dl` / `h2` / `pre` without Bootstrap grid / utility classes; `btn-danger` delete; Stimulus `confirm-submit` instead of inline `onsubmit` (CSP); `csrf_action_form()` instead of the controller form | Chrome only | **Delete.** `ui_kit_stylesheet: false`, `header_actions_in_layout: true` + bridge `block()` call, `css_classes` (`show`, `details`, `detail_term: ''`, `detail_value: ''`, `section_title`, `code`, `back`, `back_link`, `btn_delete`), `attributes.show`, `confirm_attributes`. The controller-provided delete form keeps the same CSRF id. |
| `admin/index.html.twig` | Skips `admin/base`; export / purge moved to the page header; one `section.panel` around summary + table + pagination with `data-testid="http-log-results"`; Tailwind table / header / cell classes; icon row action via UiKit `_action_inner`; Beacon `kit/_pagination.html.twig` (always shown, UiKit contract); Stimulus confirm; criteria scalarised into a flat `query` (which, like 1.2.0, lost the filters on page 2: the form reads `http_log_filter[...]`) | Chrome only (no product behaviour) | **Delete.** Same switches plus `css_classes` (`results`, `toolbar`, `table_wrapper`, `table`, `table_head_cell`, `table_row`, `table_cell`, `method_badge`, `path_cell`, `empty_cell`, `btn_export`, `btn_purge`, `action_form`, `action_form_gap: ''`), `attributes.results`, `ui_kit_row_actions: true`, `templates.pagination: 'kit/_pagination.html.twig'` (the bundle passes it the correct nested `query`, so page 2 stays filtered — fixing Beacon's bug too). Residual cosmetic deltas (muted colour on some cells, `title` on the path cell, visually-hidden label on the actions column): accept, or **shrink** to `{% extends '@!NowoHttpLogBundle/admin/index.html.twig' %}` + `nowo_http_log_table_head` / `nowo_http_log_table_row`. |
| `admin/_filter.html.twig` | Product filter standard: placeholder-only widgets generated by a `form_row` loop, shared `shared/_filter_actions.html.twig` (Filter + Clear + per-page) and `form/_fields.html.twig`, `role="search"` + `data-testid="http-log-filters"` | Host product convention | **Must stay** (it is Beacon's cross-admin filter standard, not chrome the bundle can express). It keeps working at the same path; optionally move it out of `templates/bundles/` and select it with `templates.filter`. |

Beacon config after the shrink (layout bridge `kit/http_log_admin_layout.html.twig` stays):

```yaml
# config/packages/nowo_http_log.yaml (Beacon)
nowo_http_log:
    web_ui:
        layout_template: 'kit/http_log_admin_layout.html.twig'
        css_framework: tailwind
        ui_kit_stylesheet: false
        header_actions_in_layout: true
        ui_kit_row_actions: true
        templates:
            pagination: 'kit/_pagination.html.twig'
        confirm_attributes:
            data-controller: confirm-submit
            data-action: 'submit->confirm-submit#confirm'
            data-confirm-submit-message-value: '{message}'
        attributes:
            results: { data-testid: http-log-results }
            show: { data-testid: http-log-show }
        css_classes:
            results: 'panel overflow-x-auto http-log-results'
            toolbar: 'mb-3 text-sm text-[var(--color-ink)]/70'
            summary: 'm-0'
            actions: 'http-log-results__actions inline-flex flex-wrap items-center gap-2'
            action_form: 'inline'
            action_form_gap: ''
            btn_export: 'btn-ghost'
            btn_purge: 'btn-danger'
            table_wrapper: ''
            table: 'data-table w-full text-left text-sm'
            table_head_row: 'border-b border-[var(--color-sand)] text-[var(--color-ink)]/65'
            table_head_cell: 'px-2 py-2 font-medium'
            table_row: 'row-hover border-b border-[var(--color-sand)]/70 last:border-0'
            table_cell: 'px-2 py-3'
            method_badge: 'badge bg-[var(--color-sand)] text-[var(--color-ink)]/75'
            path_cell: 'max-w-xs truncate'
            empty_cell: 'px-2 py-4 text-[var(--color-ink)]/65'
            show: 'panel http-log-show'
            back: 'm-0'
            back_link: 'btn-ghost no-underline'
            details: 'http-log-details'
            detail_term: ''
            detail_value: ''
            section_title: 'nowo-ui-section-title'
            code: 'nowo-ui-code'
            btn_delete: 'btn-danger'
```

And the bridge gains one line (the rest of `kit/http_log_admin_layout.html.twig` is unchanged):

```twig
{% set kit_admin_header_actions %}{% if block('nowo_http_log_header_actions') is defined %}{{ block('nowo_http_log_header_actions') }}{% endif %}{% endset %}
```

Notes: write a literal `%` in YAML values as `%%` (container parameters); `{message}` is the confirm placeholder
because `%message%` would be read as a parameter. Keep the CSRF ids `http_log_export`, `http_log_purge`,
`http_log_delete` and the flat `_token` field if you override the action blocks.

## Translation overrides

Symfony loads application catalogues **before** bundle fallbacks (REQ-I18N-001).

- **Domain:** `NowoHttpLogBundle` (use this in PHP `->trans(..., domain: 'NowoHttpLogBundle')` and Twig `|trans({}, 'NowoHttpLogBundle')`).
- **Override path:** `translations/NowoHttpLogBundle.<locale>.yaml` in your app (e.g. `translations/NowoHttpLogBundle.en.yaml`).
- **Behaviour:** keys defined in the app file replace bundle strings; missing keys fall back to the bundle catalogue.

**Shipped locales:** `en`, `es`, `fr`, `de`, `it`, `nl`, `pt`.

Example — customise the admin title in English:

```yaml
# translations/NowoHttpLogBundle.en.yaml
admin.title: Request audit log
```

## Export

**Admin UI:** use the export button on the filtered list (CSV or JSON, up to `export.max_sync_rows`).

**CLI:**

```bash
php bin/console nowo:http-log:export --format=csv --output=/tmp/http-log.csv
php bin/console nowo:http-log:export --format=json
```

Large exports can be dispatched asynchronously via `ExportHttpLogMessage` when Messenger is configured.

## Purge and retention

**Retention** (`retention.days`) defines how old entries may be when purging. `null` keeps all rows until manual purge.

**CLI:**

```bash
php bin/console nowo:http-log:purge
php bin/console nowo:http-log:purge --dry-run
```

**Admin UI:** purge action on the index page (requires purge permission from access checker).

Async purge uses `PurgeHttpLogMessage`.

Every purge deletes in batches of `retention.purge_batch_size` rows (default `1000`, keyset on the id), so purging
a large table does not hold one long lock or write one huge binlog event. The returned / printed count is the total
number of rows deleted.

## Ignoring routes and paths

Skip profiler, admin, or internal routes:

```yaml
nowo_http_log:
    ignore_routes:
        - '_wdt'
        - '_profiler'
        - 'api_health_*'
    ignore_path_prefixes:
        - '/admin/http-log'
        - '/internal'
```

Route patterns use `fnmatch` (case-insensitive). The admin UI path is ignored by default so browsing logs does not flood the table.

Kit-internal requests are skipped as well while `ignore_default_path_prefixes` is `true` (default): `/_nowo/`
(HotReloadBundle client and shared worker), `/sw.js` and `/manifest.webmanifest` (PwaBundle). They are merged with your
`ignore_path_prefixes`, so you do not need to list them; set `ignore_default_path_prefixes: false` to record them.

## CLI commands

| Command | Description |
| --- | --- |
| `nowo:http-log:export` | Export entries to CSV or JSON |
| `nowo:http-log:purge` | Delete entries older than retention |

Run `php bin/console list nowo:http-log` for options.

## Async processing

With `async: true` (default), capture dispatches `PersistHttpLogMessage`. Run a worker:

```bash
php bin/console messenger:consume async -vv
```

Set `async: false` to persist in the same request (after response sent, on terminate).

Configure **retries** and a **failure transport** for production async setups — see [CONFIGURATION.md](CONFIGURATION.md#messenger-retry-and-failure-transport).

## FrankenPHP worker mode

Safe under FrankenPHP worker with **no kernel reset** between requests: detach after persist, reset closed EntityManagers, firewall-gated user capture. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md) and [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md#worker-mode-without-kernel-reset).

See [INSTALLATION.md](INSTALLATION.md) and [CONFIGURATION.md](CONFIGURATION.md).
