# Templates

Admin pages of HttpLogBundle on the UiKit 2.1 page contract (REQ-UI-003). The controller builds the view models with
`Nowo\HttpLogBundle\View\AdminPages`; the templates extend a UiKit scaffold and fill data blocks only. Contract test:
`tests/Integration/PageContractTest.php` (UiKit `PageContractTestCase`).

## Table of contents

- [Pages](#pages)
- [Unique pages](#unique-pages)
- [Per-page overrides](#per-page-overrides)
- [Deprecated partials](#deprecated-partials)
- [Allowed exceptions](#allowed-exceptions)

## Pages

| Page | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Log list | `admin/index.html.twig` | `page/list` | `ListPage` (header: title, "N entries" intro, actions export CSV / JSON (POST, `FormView` `HttpLogExportType`: CSRF token, format and criteria as hidden fields), purge (POST, `FormView` `HttpLogPurgeType`, `Confirm`); columns: id, createdAt, method, path, statusCode, durationMs, clientIp; rowActions: view; filters: `HttpLogFilterType` with `filterFields` widths, `filtersSubmit`, clear link while filtered; `Pagination`; `EmptyState`; attributes `data-testid="http-log-list"`) | `nowo_ui_list_row` |
| Log entry | `admin/show.html.twig` | `page/show` | `ShowPage` (header: title, breadcrumbs list > entry, actions back + delete (POST, `FormView` `HttpLogDeleteType`, `Confirm`); one `DetailSection` with the request / response summary; attributes `data-testid="http-log-show"`) + `entry` (`HttpLogEntry`) | `nowo_ui_show_after` (payload cards, one block each: `nowo_http_log_section_query_params`, `nowo_http_log_section_request_headers`, `nowo_http_log_section_response_headers`, `nowo_http_log_section_request_body`, `nowo_http_log_section_response_body`, inside `nowo_http_log_detail_sections`) |

Both pages pass `bundle: 'NowoHttpLogBundle'` (theme exception `nowo_ui_kit.theme.bundles.NowoHttpLogBundle`) and
`layout: web_ui.layout_template` (null = `nowo_ui_layout.admin`). Bundle-prefixed semantic classes (no styles shipped):
`nowo-http-log-method`, `nowo-http-log-status`, `nowo-http-log-path`, `nowo-http-log-payload`, `nowo-http-log-code`.
Payload `<pre>` blocks use the `code_block` slot (`nowo_ui_class('code_block', bundle)` + `nowo_ui_attrs`); they keep
`nowo-http-log-code` after the slot classes as a stable hook for host CSS (BC with 1.x, no styles shipped).
Status badges use the slots `badge_success` (< 300), `badge_info` (3xx), `badge_warning` (4xx), `badge_danger` (5xx);
the method badge `badge_neutral`.

## Unique pages

- (none)

## Per-page overrides

`nowo_http_log.web_ui.templates.<page>` (`index`, `show`): a host template that extends
`@!NowoHttpLogBundle/admin/<page>.html.twig` and overrides a data block (`nowo_ui_list_row`, `nowo_ui_list_after`,
`nowo_ui_list_filters`, `nowo_ui_show_sections`, `nowo_ui_show_after` or one `nowo_http_log_section_*` block).

## Deprecated partials

- `admin/_filter.html.twig` — deprecated since 1.8, removed in 2.0. Not used by default. Hosts that set
  `web_ui.templates.filter` get it bridged to the theme partial `list_filters` of this bundle; their template may extend
  `@!NowoHttpLogBundle/admin/_filter.html.twig` and override `nowo_http_log_filter_panel` (default: UiKit's
  `list_filters` partial) or `nowo_http_log_filter_fields` / `nowo_http_log_filter_actions` (the form then renders with
  the `filters` / `filters_actions` slots).

## Allowed exceptions

- (none) — `allowedClasses()` is not overridden in the contract test.
