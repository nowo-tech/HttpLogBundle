# UiKit page contract — Http Log Bundle

**Package:** `nowo-tech/http-log-bundle`  
**Namespace:** `Nowo\HttpLogBundle`  
**Config alias:** `nowo_http_log`  
**Status:** Implemented (unreleased, planned 1.8.0)  
**Origin:** REQ-UI-003 (standard page contract, UiKit 2.1), phase 1 of the migration matrix in
`BUNDLES_UI_PAGE_CONTRACT.md` (§5 row HttpLog)

## Overview

The admin pages stop owning their chrome. The list extends UiKit's `page/list` scaffold and the entry page
`page/show`; the controller passes UiKit view models built by `View\AdminPages`. The bundle CDN layout, its page shell
and its local pagination / macro partials are removed. The 1.x chrome keys (spec 002) stay one minor version as
deprecated aliases, bridged into `nowo_ui_kit.theme.bundles.NowoHttpLogBundle`.

## User scenarios (`US-*`)

### US-01 — Themed once (Priority: P1)

As a host integrator, the HttpLog pages render inside my UiKit layout with my `nowo_ui_kit.theme`, like every other
UiKit-based bundle, without HttpLog-specific config.

**Acceptance:** With only `nowo_ui_kit.layouts.admin` and `nowo_ui_kit.theme` configured, the list and entry pages
render in the host layout (canonical blocks), every framework class comes from a theme slot, and purge / delete
confirm in the UiKit dialog (no inline JavaScript).

### US-02 — Upgrade without breakage (Priority: P1)

As a host that customised 1.x, my config keeps working and tells me what to change.

**Acceptance:** Every 1.x `web_ui` key is still accepted; deprecated keys trigger a Symfony deprecation; class /
attribute / partial overrides that differ from the 1.x defaults reach the pages through the bridge; a
`web_ui.templates.filter` template that extends `admin/_filter.html.twig` and overrides the `nowo_http_log_filter_*`
blocks still renders.

## Functional requirements (`FR-*`)

| ID | Requirement |
| --- | --- |
| FR-UIC-001 | `admin/index.html.twig` extends `@NowoUiKit/page/list.html.twig` and fills `nowo_ui_list_row`; `admin/show.html.twig` extends `@NowoUiKit/page/show.html.twig` and fills `nowo_ui_show_after` (payload blocks `nowo_http_log_section_*` kept). |
| FR-UIC-002 | `View\AdminPages` builds `ListPage` (header actions export per enabled format, purge with `Confirm`; columns; view row action; filter fields; `Pagination`; `EmptyState`; clear link while filtered) and `ShowPage` (back, delete with `Confirm`, breadcrumbs, summary `DetailSection`). Export, purge and delete are `FormView`-backed actions (UiKit 2.1 prints their hidden fields on every render: CSRF token, export `format` + criteria); the controller reads them from the POST body. Payload `<pre>` blocks use the `code_block` slot and keep `nowo-http-log-code`. |
| FR-UIC-003 | Actions the access checker denies (`canExport`, `canPurge`) are not visible. |
| FR-UIC-004 | `web_ui.layout_template` defaults to `null` (UiKit layout); the 1.x default `@NowoHttpLogBundle/layout.html.twig` normalises to `null`. |
| FR-UIC-005 | Deprecated (removed in 2.0): `css_framework`, `icon_set` (seeded into `nowo_ui_kit` only when set here and not there), `templates.filter`, `templates.pagination`, `css_classes`, `attributes` (bridged by `LegacyThemeBridge`), `ui_kit_stylesheet`, `header_actions_in_layout`, `confirm_attributes`, `ui_kit_row_actions` (no effect). |
| FR-UIC-006 | Removed: `layout.html.twig`, `admin/base.html.twig`, `admin/_pagination.html.twig`, `admin/_macros.html.twig`, Twig globals `nowo_http_log_layout_template` / `nowo_http_log_css_framework`. |

## Non-goals

- New admin pages (settings, dashboard) or sortable columns.
- Changes to UiKit (gaps are reported to the coordinator instead).

## Success criteria

| ID | Criterion |
| --- | --- |
| SC-01 | `PageContractTest` (UiKit `PageContractTestCase`) passes for list, empty list, entry and entry without payloads with `none`, `bootstrap5` and `tailwind` |
| SC-02 | `AdminPagesRenderTest` covers cells, actions with CSRF, a page_header override re-rendering actions, the filter / pagination / class / attribute bridges, `layout_template` and page overrides |
| SC-03 | Config tests cover every deprecation message and the bridge; coverage stays at 100 % lines |
| SC-04 | Demo: admin pages 200 inside the UiKit layout bridge, no bundle deprecations on `cache:clear`, `lint:container` green, WebP screenshots re-captured |
