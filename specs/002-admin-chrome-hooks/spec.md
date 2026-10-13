# Admin chrome hooks — Http Log Bundle

**Package:** `nowo-tech/http-log-bundle`  
**Namespace:** `Nowo\HttpLogBundle`  
**Config alias:** `nowo_http_log`  
**Status:** Released in 1.3.0  
**Origin:** Symfony Beacon spec 134 FR-010 (kit upstream improvements: expose blocks / slots / config so host page forks shrink to a `layout_template` bridge plus config)

## Overview

Before 1.3.0 a host that wanted the admin list / detail pages to match its own design system had to copy
`admin/index.html.twig`, `admin/show.html.twig` and `admin/_filter.html.twig` (Beacon did). Those forks froze
the markup, so vendor fixes (new logged fields, form changes) stopped reaching the host.

1.3.0 adds **chrome hooks**: Twig blocks around every chrome region, a CSS class map and an attribute map,
configurable partial / page template paths, and a few presentation switches. Defaults reproduce the 1.2.0
markup (whitespace-equivalent), so existing apps see no change.

## User scenarios (`US-*`)

### US-01 — Theme the admin pages from config (Priority: P1)

As a host integrator, I restyle the list and detail pages (classes, wrappers, data attributes, button styles)
from `nowo_http_log.web_ui` so I do not fork vendor templates.

**Acceptance:** Setting `web_ui.css_classes` / `web_ui.attributes` changes the rendered classes and attributes;
unset keys keep the 1.2.0 markup.

### US-02 — Move header actions into the host layout (Priority: P1)

As a host integrator with a page-header actions slot, I render the export / purge buttons (list) and the back link
(detail) in my layout header instead of the page toolbar.

**Acceptance:** With `web_ui.header_actions_in_layout: true` the pages skip the inline actions and the layout
renders `{{ block('nowo_http_log_header_actions') }}`; each form is rendered exactly once.

### US-03 — Override one region only (Priority: P2)

As a host integrator, I override a single region (row actions, empty state, a detail section) with a thin template
that extends the bundle page and redefines one block.

**Acceptance:** A template extending `@!NowoHttpLogBundle/admin/index.html.twig` (or set as
`web_ui.templates.index`) overrides only the blocks it defines.

### US-04 — Plug the host pagination / filter partial (Priority: P2)

As a host integrator, I point the list page at my own pagination or filter partial.

**Acceptance:** `web_ui.templates.pagination` / `web_ui.templates.filter` are included instead of the bundle
partials; the pagination include receives a UiKit-compatible contract.

### US-05 — CSP-friendly confirmations (Priority: P2)

As a host with a strict Content-Security-Policy, I replace the inline `onsubmit="return confirm(…)"` on the purge /
delete forms with data attributes for my own JS controller.

**Acceptance:** With `web_ui.confirm_attributes` set, no `onsubmit` attribute is rendered and `{message}` is
replaced by the translated prompt.

## Functional requirements (`FR-*`)

| ID | Requirement |
| --- | --- |
| FR-CHR-001 | Admin templates expose blocks that wrap the existing markup. **index:** `nowo_http_log_page_header` (> `nowo_http_log_intro`), `nowo_http_log_filter`, `nowo_http_log_toolbar` (> `nowo_http_log_summary`, `nowo_http_log_header_actions` > `nowo_http_log_action_export_csv`, `nowo_http_log_action_export_json`, `nowo_http_log_action_purge`), `nowo_http_log_table` (> `nowo_http_log_table_head`, `nowo_http_log_table_row` > `nowo_http_log_row_actions`, `nowo_http_log_empty_state`), `nowo_http_log_pagination`. **show:** `nowo_http_log_page_header` (> `nowo_http_log_intro`), `nowo_http_log_header_actions`, `nowo_http_log_details`, `nowo_http_log_detail_sections` (> `nowo_http_log_section_query_params`, `_request_headers`, `_response_headers`, `_request_body`, `_response_body`), `nowo_http_log_delete`. **_filter:** `nowo_http_log_filter_panel` (> `nowo_http_log_filter_fields`, `nowo_http_log_filter_actions`). |
| FR-CHR-002 | `web_ui.templates.{index,show,filter,pagination}` select the page the controller renders and the partials the list page includes (defaults: bundle templates). The pagination include receives `pagination {page, per_page, total, total_pages}`, `pages`, `page_size`, `item_count`, `route`, `route_params`, `query` (active filters as the filter form reads them: `{http_log_filter: {field: value}}`, dates as `Y-m-d\TH:i`), `nav_label`, `nav_trans_domain`, `framework`. |
| FR-CHR-003 | `web_ui.css_classes` is a strict class map (unknown keys rejected). Defaults equal the 1.2.0 classes; `null` means "UiKit macro for `css_framework`" (`ui.btn`, `ui.row_actions`); `''` renders no class attribute. `results` / `show` (default `''`) add an optional wrapper element. |
| FR-CHR-004 | `web_ui.attributes` adds HTML attributes (dashed names preserved) to `filter`, `filter_form`, `results`, `toolbar`, `table_wrapper`, `table`, `show`, `details`, `delete_form`; other targets are rejected. A non-empty `results` / `show` map renders the wrapper even without a class. |
| FR-CHR-005 | `web_ui.ui_kit_stylesheet` (default `true`) controls whether `admin/base.html.twig` appends UiKit `nowo-ui.css`. |
| FR-CHR-006 | `web_ui.header_actions_in_layout` (default `false`): pages skip the inline header actions; the bundle layout renders them in its header; host layouts call `block('nowo_http_log_header_actions')`. |
| FR-CHR-007 | `web_ui.confirm_attributes` (default `[]`) replaces the inline `onsubmit` confirm on purge / delete forms; `{message}` is substituted with the translated prompt. |
| FR-CHR-008 | `web_ui.ui_kit_row_actions` (default `false`) renders list row actions with `@NowoUiKitBundle/partials/_row_actions.html.twig` (honours `nowo_ui_kit.row_actions_display`; needs UiKit >= 1.6). |
| FR-CHR-009 | `TwigPathsPass` registers the bundle views under `@!NowoHttpLogBundle` so an override in `templates/bundles/NowoHttpLogBundle/` can extend the page it replaces. |
| FR-CHR-010 | The controller passes the resolved chrome (`AdminChrome::fromWebUi()`) to Twig as `nowo_http_log_ui`; templates fall back to `AdminChrome::DEFAULTS`. `%` in chrome values survives container parameter resolution. |
| FR-CHR-011 | Flash messages (export queued, purged, deleted) use the `NowoHttpLogBundle` catalogue (`flash.*`, English fallback without a translator); all seven catalogues are translated. |
| FR-CHR-012 | With default configuration the rendered list / detail pages are whitespace-equivalent to 1.2.0 (golden files), except the pagination links fixed by FR-CHR-013. |
| FR-CHR-013 | (Fix) Pagination links carry the active criteria in the shape the GET filter form reads (`http_log_filter[field]=value`, dates as `Y-m-d\TH:i`), so page N keeps the filters; the same nested `query` is passed to `web_ui.templates.pagination`. |

## Non-goals

- Changing product behaviour (filters, criteria, export / purge semantics, CSRF ids, routes).
- Shipping host design tokens or CSS.

## Success criteria

| ID | Criterion |
| --- | --- |
| SC-01 | `tests/Integration/AdminChromeRenderTest.php` renders the real templates (forms, routing, translations, UiKit) and matches the 1.2.0 golden files (pagination hrefs updated for FR-CHR-013) with and without `nowo_http_log_ui` |
| SC-02 | Render tests cover the class map, attributes, partial paths, thin forks via `@!NowoHttpLogBundle`, header actions in bundle and host layouts, confirm attributes, UiKit stylesheet and row-action switches |
| SC-03 | Configuration tests cover defaults, overrides, dashed keys, and rejection of unknown class / attribute keys and empty template paths |
| SC-04 | Coverage stays at 100 % lines; PHPStan, CS, Rector, Twig lint, Igor, translation parity green |
| SC-05 | `docs/USAGE.md` "Customising the admin chrome" documents every hook and gives the Beacon per-fork verdict |
| SC-06 | `tests/Integration/PaginationKeepsFiltersTest.php`: filtered list → "next" link carries `http_log_filter[...]` → page 2 is still filtered |

## Validation

```bash
make cs-check rector-dry phpstan igor validate-translations twig-lint test-coverage
```

## Related

- [001-baseline](../001-baseline/spec.md) — FR-UI-001, FR-TWIG-001, FR-I18N-002
- [docs/USAGE.md — Customising the admin chrome](../../docs/USAGE.md#customising-the-admin-chrome)
- [docs/CONFIGURATION.md — web_ui](../../docs/CONFIGURATION.md#web_ui)
