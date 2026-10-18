# Upgrading

This document describes how to upgrade between versions of **Http Log Bundle**.

## Table of contents

- [Unreleased](#unreleased)
- [To 1.8.0](#to-180)
  - [Template contract](#template-contract)
  - [Deprecated configuration](#deprecated-configuration)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.1](#to-151)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
- [To 1.2.0](#to-120)
- [To 1.1.7](#to-117)
- [1.1.6](#116)
- [From 1.1.4 to 1.1.5](#from-114-to-115)
- [1.1.4 (Flex when@prod PII + access_control)](#114-flex-whenprod-pii--access_control)
- [1.1.3 (maintainer lock refresh)](#113-maintainer-lock-refresh)
- [1.1.2 (composer audit CI)](#112-composer-audit-ci)
- [1.1.1 (Symfony 8 demos / Hot Reload 1.4)](#111-symfony-8-demos--hot-reload-14)
- [1.1.0](#110)
  - [UiKitBundle (REQ-UI-001-kit)](#uikitbundle-req-ui-001-kit)
  - [FormKitBundle (admin forms)](#formkitbundle-admin-forms)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)
- [1.0.2](#102)
  - [Behaviour](#behaviour)
  - [Action required only if you customized Twig](#action-required-only-if-you-customized-twig)
- [1.0.1](#101)
- [1.0.0](#100)
  - [Install](#install)
  - [From this version onward](#from-this-version-onward)

## Unreleased

- **WebProfiler paths ignored by default (behaviour change):** with `ignore_default_path_prefixes: true` (default) the recorder now also skips `/_wdt/` and `/_profiler/`. You can remove them from your `ignore_path_prefixes` (duplicates are harmless). If you deliberately recorded profiler traffic, set `ignore_default_path_prefixes: false` and list the kit paths you still want skipped (`/_nowo/`, `/sw.js`, `/manifest.webmanifest`).
- **Host with port:** new entries store the host with its port when the request used a non-default one (`getHttpHost()`). Queries or exports that compare `host` with a bare hostname should use a prefix match (`host LIKE 'example.test%'`).
- **Entry page template:** `admin/show.html.twig` overrides `nowo_ui_show_sections` (summary with badges / code slot) and expects a `payload` variable (passed by the bundle controller; `AdminPages::payload($entry)`). A host controller rendering the template without it still gets the 1.8 JSON blocks. Overrides of `nowo_http_log_section_*` blocks keep working. Run `assets:install` so `bundles/nowohttplog/css/nowo-http-log-admin.css` is published.
- **Filter form:** the children of `HttpLogFilterType` are reordered (`q`, `method`, `statusCode`, `clientIp`, `path`, `routeName`, `bodyContentType`, `createdFrom`, `createdTo`); a host `_filter.html.twig` override that renders fields by position should render them by name.

## To 1.8.0

From **1.7.0**:

```bash
composer update nowo-tech/http-log-bundle
```

Standard page contract (REQ-UI-003, UiKit 2.1). Requires `nowo-tech/ui-kit-bundle` **^2.1** (2.1 keeps the CSRF
token of `FormView`-backed header actions when a host `page_header` override renders them, and adds the `code_block`
slot). Minor release: every
1.x key keeps working (deprecated, bridged into the UiKit theme); what changes is the markup of the two admin pages.

```bash
composer update nowo-tech/http-log-bundle nowo-tech/ui-kit-bundle
```

1. **Layout.** The pages now extend UiKit scaffolds, which extend `nowo_ui_kit.layouts.admin` (default
   `@NowoUiKit/layout/admin.html.twig`) — or `nowo_http_log.web_ui.layout_template` when you set it. That layout must
   render the canonical blocks (`nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_navigation`,
   `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts`) and load
   `nowo-ui.css` + the UiKit scripts (`nowo-ui-confirm.js` opens the purge / delete dialogs). A 1.x bridge that only
   mapped `nowo_ui_content` loses the page header (title, export / purge / back / delete buttons): add
   `nowo_ui_page_header` (and the other blocks), see UiKit `docs/PAGE_CONTRACT.md` "Example: host integration".
   The bundle CDN layout `@NowoHttpLogBundle/layout.html.twig` is gone; that value of `layout_template` is read as
   `null` (UiKit layout).
2. **Overrides.** Re-check every `templates/bundles/NowoHttpLogBundle/` file and every `web_ui.templates.*` target
   against [TEMPLATES.md](TEMPLATES.md) — most can be deleted in favour of `nowo_ui_kit.theme`.
3. **Config.** Remove the deprecated keys below when convenient (they keep working until 2.0).

### Template contract

What hosts may notice:

| 1.x | 1.8 |
| --- | --- |
| `admin/index.html.twig` with its own toolbar, table, `_filter` and `_pagination` | extends `@NowoUiKit/page/list.html.twig`; fills `nowo_ui_list_row` only |
| `admin/show.html.twig` with back link, `<dl class="row">`, `<pre>` sections, delete form | extends `@NowoUiKit/page/show.html.twig`; summary = `DetailSection`, payload cards in `nowo_ui_show_after` |
| Payload `<pre class="nowo-ui-code bg-light p-3">` (`css_classes.code`) | `<pre>` with the UiKit `code_block` slot (`nowo-ui-code-block`, Bootstrap `border p-3`, scrolls above `--nowo-ui-code-block-max-height`) + `nowo-http-log-code` (stable hook, no styles shipped) |
| `layout.html.twig` (Bootstrap CDN), `admin/base.html.twig`, `admin/_pagination.html.twig`, `admin/_macros.html.twig` | **removed** (UiKit layout, scaffold, `pagination` partial, theme slots) |
| `admin/_filter.html.twig` (always included) | deprecated BC partial, used only through `web_ui.templates.filter` (bridged to the theme partial `list_filters`) |
| Twig context `filterForm`, `entries`, `total`, `page` (int), `pageSize`, `exportCsvForm`, `exportJsonForm`, `purgeForm`, `deleteForm`, `nowo_http_log_ui` | `page` = `Nowo\UiKitBundle\View\ListPage` / `ShowPage` (+ `criteria` on the list, `entry` on the detail page) |
| Blocks `nowo_http_log_page_header`, `_intro`, `_filter`, `_toolbar`, `_summary`, `_header_actions`, `_action_export_csv/json`, `_action_purge`, `_table`, `_table_head`, `_table_row`, `_row_actions`, `_empty_state`, `_pagination`, `_details`, `_delete` | removed — use the scaffold data blocks (`nowo_ui_list_row`, `nowo_ui_list_filters`, `nowo_ui_list_after`, `nowo_ui_show_sections`, `nowo_ui_show_after`) or the theme |
| Blocks `nowo_http_log_detail_sections`, `nowo_http_log_section_*` | kept (inside `nowo_ui_show_after`, context `entry`) |
| Blocks `nowo_http_log_filter_panel`, `_fields`, `_actions` | kept in the deprecated `admin/_filter.html.twig`; `filterForm` = `page.filters` |
| Inline `onsubmit="return confirm(…)"` / `confirm_attributes` | UiKit confirm `<dialog>` (no inline JS, CSP-safe) |
| Export / purge / delete forms (`form_start` + hidden criteria) | UiKit `FormView`-backed POST actions (same forms: flat `_token`, CSRF ids `http_log_export`, `http_log_purge`, `http_log_delete`; export posts `format` and the criteria as hidden fields, unchanged); purge / delete confirm in a dialog |
| Export / purge buttons always shown | hidden for users the access checker denies `canExport` / `canPurge` (the routes still enforce it) |
| Twig globals `nowo_http_log_layout_template`, `nowo_http_log_css_framework` | removed (`nowo_http_log_path_prefix`, `nowo_http_log_page_size` kept) |
| Status code as plain text, method badge `badge bg-secondary` | status badges per class (`badge_success` / `_info` / `_warning` / `_danger` slots), method badge slot `badge_neutral` |
| Pagination "Page N of M" | UiKit pagination (numbered, "Showing x–y of n"); filters kept across pages from the request query |

Hosts that tried the unreleased `feature/ui-page-contract` branch on UiKit 2.0: header actions are `FormView`-backed
again and the export route no longer reads `format` / criteria from the URL query (POST fields only, as in 1.x); the
payload `<pre>` gained the `code_block` slot classes. Host `page_header` overrides that render `actions` through
`nowo_ui_partial('action')` keep the CSRF tokens (UiKit 2.1).

A pagination override set with `web_ui.templates.pagination` now receives UiKit's pagination input (`pagination`,
`pagination_model`, `item_count`, `route`, `route_params`, `query`); `pages`, `page_size`, `nav_label`,
`nav_trans_domain` and `framework` are no longer passed.

### Deprecated configuration

| Key | Replacement | Behaviour until 2.0 |
| --- | --- | --- |
| `web_ui.css_framework` | `nowo_ui_kit.css_framework` | seeded into UiKit when set here and not in `nowo_ui_kit` (no longer seeded by default: both default to `bootstrap5`) |
| `web_ui.icon_set` | `nowo_ui_kit.icon_set` | same (both default to `bootstrap-icons`) |
| `web_ui.templates.filter` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.partials.list_filters` | bridged |
| `web_ui.templates.pagination` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.partials.pagination` | bridged (the 1.x default path is ignored) |
| `web_ui.css_classes.*` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.classes.<slot>` | bridged when the value differs from the 1.x default (mapping below) |
| `web_ui.attributes.*` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.attributes.<slot>` | bridged (`on*` dropped); `results` / `show` go on the page root |
| `web_ui.ui_kit_stylesheet` | — | no effect (the layout loads `nowo-ui.css`) |
| `web_ui.header_actions_in_layout` | — | no effect (header actions are in `nowo_ui_page_header`) |
| `web_ui.confirm_attributes` | — | no effect (UiKit confirm dialog) |
| `web_ui.ui_kit_row_actions` | `nowo_ui_kit.row_actions_display` | no effect (always the UiKit `row_actions` partial) |
| `Nowo\HttpLogBundle\View\AdminChrome`, parameter `nowo_http_log.web_ui.chrome` | the UiKit theme | kept, unused by the pages |

`css_classes` → slot: `filter`, `filter_form` → `filters`; `btn_filter` → `btn_primary`; `toolbar` → `toolbar`;
`summary` → `page_intro`; `btn_export` → `btn_secondary`; `btn_purge`, `btn_delete` → `btn_danger`; `table_wrapper` →
`table_wrap`; `table`, `table_head_row`, `table_head_cell`, `table_row`, `table_cell` → same name; `method_badge` →
`badge_neutral`; `row_actions` → `row_actions`; `btn_view` → `action_neutral`; `empty_cell` → `empty`; `pagination` →
`pagination`; `page_link` → `pagination_link`; `details` → `detail_list`; `detail_term`, `detail_value` → same name;
`section_title` → `card_title`; `code` → `code_block`. No slot (dropped): `filter_body`, `results`, `actions`,
`action_form`, `action_form_gap`, `path_cell`, `pagination_list`, `page_item`, `show`, `back`, `back_link`,
`delete_form`.
`attributes` → slot: `filter`, `filter_form` → `filters`; `toolbar`; `table_wrapper` → `table_wrap`; `table`;
`details` → `detail_list`; `results` / `show` → page root; `delete_form` dropped.

The bridge is prepended to `nowo_ui_kit`, so your own `nowo_ui_kit.theme.bundles.NowoHttpLogBundle` wins. Values that
were full framework classes (`btn btn-danger`) are usually redundant now: the slot defaults of `nowo_ui_kit.css_framework`
already carry them — move only the host-specific classes into the theme.

## To 1.7.0

From **1.6.0** — kit asset paths ignored by default; keyset batch purge. No action required.

```bash
composer update nowo-tech/http-log-bundle
```

- **Kit ignore paths (`ignore_default_path_prefixes`, default `true`):** requests under `/_nowo/` (HotReloadBundle), `/sw.js` and `/manifest.webmanifest` (PwaBundle) are no longer recorded. They are *merged* with `ignore_path_prefixes` instead of being added to its default list on purpose: Symfony replaces a list default as soon as the host sets the key, so changing the default would have reached only hosts without their own list. With the toggle, every host gets them, and a host that already lists its own prefixes keeps every entry it had (the default of `ignore_path_prefixes` is still `['/admin/http-log']`). You can now remove `/_nowo/`, `/sw.js` and `/manifest.webmanifest` from your `ignore_path_prefixes` (keeping them is harmless: duplicates are removed). To keep recording them, set `ignore_default_path_prefixes: false`.
- **Keyset purge (`retention.purge_batch_size`, default `1000`):** `nowo:http-log:purge`, the admin purge and `PurgeHttpLogMessage` delete at most that many rows per statement. Counts and messages are unchanged. Lower it on busy MySQL primaries / replicas, raise it for faster purges on small instances. If you call `HttpLogEntryRepository::deleteOlderThan()` yourself, it now takes an optional `int $batchSize` (default `1000`, `< 1` throws). The filtered admin purge now uses the same size (was a fixed 500).

## To 1.6.0

From **1.5.1** — no raw FormKit convention keys in the admin filter (requires form-kit ^2.10)

```bash
composer update nowo-tech/http-log-bundle
```

- **FormKit ^2.10 required:** run `composer update nowo-tech/http-log-bundle nowo-tech/form-kit-bundle --with-dependencies`.
- **Filter form help / placeholder:** the seeded `http_log` FormKit profile sets `convention_requires_translation: true`, so the filter fields only show `http_log_filter.<field>.help` / `.placeholder` texts you actually translate (domain `NowoHttpLogBundle`); the raw keys are no longer displayed. If you define your own `nowo_form_kit.profiles.http_log`, nothing is seeded: add `convention_requires_translation: true` (or `auto_help: false` / `auto_placeholder: false`) there to get the same result.

## To 1.5.1

From **1.5.0** — requests under the admin web_ui.path_prefix are never recorded.

```bash
composer update nowo-tech/http-log-bundle
```

- **Admin UI path always ignored:** while `web_ui.enabled` is true, requests under `web_ui.path_prefix` (segment-aware) are never recorded, even if the prefix is not listed in `ignore_path_prefixes`. Adding it there manually is no longer needed (harmless if kept). If you deliberately logged admin UI traffic (by overriding both `ignore_path_prefixes` and `ignore_routes`), that traffic is no longer recorded.

## To 1.5.0

From **1.4.0** — fixes - findOneBy field whitelist, path_prefix/export.formats/icon_set wired, Symfony >=7.4.

```bash
composer update nowo-tech/http-log-bundle
```

- **Symfony 7.4+:** the minimum Symfony version is now 7.4 (7.0–7.3 are end of life and were never really tested).
- **Admin route prefix:** `web_ui.path_prefix` now moves the admin routes. If you set it to something other than `/admin/http-log` while keeping the UI there, either remove the key or update your `access_control` / links to the new path. The recorder skips `web_ui.path_prefix` automatically (since the next release after 1.5.0; on 1.5.0 itself add the new prefix to `ignore_path_prefixes` or keep `nowo_http_log_*` in `ignore_routes`). If you import the routes with your own `prefix:`, it is added in front of `path_prefix`.
- **Export formats:** `export.formats` now limits the UI buttons, the export action and `nowo:http-log:export`. Only `csv` and `json` are accepted, and the list cannot be empty.
- **`findOneBy()` on `HttpLogEntryRepository`:** unknown field names now throw `InvalidArgumentException` instead of producing DQL.
- **Templates:** if you override `admin/index.html.twig`, `exportCsvForm` / `exportJsonForm` can be `null` when that format is disabled; guard with `{% if exportCsvForm %}`.

## To 1.4.0

From **1.3.1** — security - redact before truncation, redact form bodies, fail closed on empty access_roles.

```bash
composer update nowo-tech/http-log-bundle
```

- **Behaviour change:** `security.access_roles: []` used to grant access to everyone (including anonymous users); it now denies everyone. Set roles, or use `allow_unauthenticated: true` / a custom `access_checker` to open the admin UI on purpose.
- Bodies are now redacted before truncation; form-urlencoded and multipart text fields are redacted by key, and JSON-looking bodies that cannot be parsed are stored as `[REDACTED-UNPARSEABLE]`. Default redacted keys now include `_password`, `_token` and `_csrf_token`, so some stored bodies will differ.
- A negative or non-integer `older_than_days` on purge now returns HTTP 400 (the purge command rejects negative `--days`).

## To 1.3.1

From **1.3.0** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/http-log-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.3.0

From **1.2.0** — admin chrome hooks (blocks, class map, partial paths) and a pagination fix. No breaking change: with no new keys the admin pages render the same markup, except that pagination links now keep the active filters.

```bash
composer update nowo-tech/http-log-bundle
php bin/console cache:clear
```

- **Nothing to do** if you use the bundle templates as they are.
- **You forked `admin/index.html.twig`, `admin/show.html.twig` or `admin/_filter.html.twig`:** your copies keep working (same variables, routes, CSRF ids). Review them against [USAGE.md — Customising the admin chrome](USAGE.md#customising-the-admin-chrome): most chrome-only forks can be deleted in favour of `web_ui.css_classes` / `web_ui.attributes` / `web_ui.templates` / `header_actions_in_layout` / `confirm_attributes`, or shrunk to `{% extends '@!NowoHttpLogBundle/admin/index.html.twig' %}` plus one or two `nowo_http_log_*` blocks.
- **Host layout with a page-header actions slot:** set `web_ui.header_actions_in_layout: true` and render `{{ block('nowo_http_log_header_actions') }}` (guarded by `is defined`) from your layout or bridge.
- **Strict CSP:** set `web_ui.confirm_attributes` (e.g. `data-controller`, `data-action`, `data-…-message-value: '{message}'`) to drop the inline `onsubmit` handlers.
- **Flash messages** are now translated (`flash.*` keys). If you asserted the English texts in other locales, update the assertions or override the keys in `translations/NowoHttpLogBundle.<locale>.yaml`. The de / fr / it / nl / pt admin strings are now translated too; override keys you want to keep in English.
- **Pagination now keeps the filters** (bug fix): links carry `http_log_filter[field]=value` instead of flat `field=value`. If you forked `admin/index.html.twig` or wrote your own pagination partial, build the links from the `query` variable the list page now passes to `web_ui.templates.pagination` (`{http_log_filter: {…}}`, dates as `Y-m-d\TH:i`) — or switch to the bundle page plus `templates.pagination` — otherwise page 2 still loses the filters. Bookmarked URLs with the old flat keys simply open unfiltered, as before.
- `ui_kit_row_actions: true` needs `nowo-tech/ui-kit-bundle` >= 1.6 (`_row_actions` partial).

## To 1.2.0

From **1.1.7** — new optional config key, Doctrine ORM floor raised.

```bash
composer update nowo-tech/http-log-bundle
php bin/console cache:clear
```

- **Doctrine ORM:** the bundle now requires `doctrine/orm` `^3.7` (it uses the `SortDirection` enum). Apps still on ORM 2.x or < 3.7 must upgrade ORM first.
- **New option `ignore_unrouted`** (default `false`, no behaviour change): set it to `true` to stop logging requests no route matched (scanner 404s such as `/wp-login.php`, `/.env`):

```yaml
nowo_http_log:
    ignore_unrouted: true
```

## To 1.1.7

From **1.1.6** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/http-log-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## 1.1.6

FrankenPHP / long-lived kernel (worker mode **without** kernel reset). **No configuration key changes.**

```bash
composer update nowo-tech/http-log-bundle
php bin/console cache:clear
```

Behaviour changes:

- `userIdentifier` is stored only when the request matched a firewall with security enabled. Requests outside any firewall or under `security: false` are logged without a user (in classic PHP-FPM they never had one).
- `HttpLogRecorder::persistCapture()` detaches the entry after flushing and may reset a closed EntityManager via `ManagerRegistry::resetManager()`. Decorators or listeners that expected the new `HttpLogEntry` to stay managed after the flush must re-fetch it from the repository.
- `HttpLogRecorder` has two new optional trailing constructor arguments (`$managerRegistry`, `$security`); code constructing it manually keeps working.
- `HttpLogEntryRepository` resolves the EntityManager from the registry on every call. Admin list/detail and export detach loaded rows after use; purge selects ids only.

See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## From 1.1.4 to 1.1.5

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/http-log-bundle
```

## 1.1.4 (Flex when@prod PII + access_control)

Review Flex recipe `when@prod` retention/sampling and `security_nowo_http_log.yaml` after update. Schedule `nowo:http-log:purge` if not already.

```bash
composer update nowo-tech/http-log-bundle
```

## 1.1.3 (maintainer lock refresh)

No application upgrade steps.

```bash
composer update nowo-tech/http-log-bundle
```

## 1.1.2 (composer audit CI)

No application upgrade steps.

```bash
composer update nowo-tech/http-log-bundle
php bin/console cache:clear
```

## 1.1.1 (Symfony 8 demos / Hot Reload 1.4)

- No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

## 1.1.0

From **1.0.2** — Adds FormKit, UiKit, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register `TwigExtraBundle`, `NowoFormKitBundle`, and `NowoUiKitBundle` if Flex did not. See [CHANGELOG](CHANGELOG.md).

```bash
composer update nowo-tech/http-log-bundle
php bin/console assets:install
php bin/console cache:clear
```

### UiKitBundle (REQ-UI-001-kit)

Admin UI depends on **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`).

1. The package is pulled transitively; run `assets:install` so `css/nowo-ui.css` is available under the `nowo_ui_kit` package.
2. Stylesheet: `asset('css/nowo-ui.css', 'nowo_ui_kit')` via `admin/base.html.twig`.
3. Optional: set `nowo_ui_kit.css_framework` / `icon_set` in the host. If unset, HttpLog seeds those keys from `web_ui.css_framework` and defaults `icon_set` to `bootstrap-icons`.
4. Template overrides: extend `@NowoHttpLogBundle/admin/base.html.twig` and prefer `ui.flash()` / `ui.btn()` over hard-coded Bootstrap alert/button classes.

### FormKitBundle (admin forms)

Ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `http_log` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it.

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

## 1.0.2

Admin UI layout stacking (REQ-UI-001). Configuration keys and public PHP API are unchanged.

```bash
composer update nowo-tech/http-log-bundle
```

### Behaviour

- List/detail templates extend `@NowoHttpLogBundle/admin/base.html.twig`, which extends `web_ui.layout_template` and stacks host `stylesheets` / `javascripts` with `{{ parent() }}`.
- Default demo `layout.html.twig` asset blocks are named `stylesheets` / `javascripts` (Symfony convention). Nested hooks `nowo_ui_styles` / `nowo_ui_scripts` remain on the base shell. Content block `nowo_ui_content` is unchanged.

### Action required only if you customized Twig

| Situation | What to do |
| --- | --- |
| Default bundle layout (no overrides) | None — clear cache if admin CSS/JS looks wrong. |
| Frozen override of `layout.html.twig` that used `nowo_ui_styles` / `nowo_ui_scripts` as top-level blocks | Rename those blocks to `stylesheets` / `javascripts`, or remove the override and rely on config. |
| Custom `web_ui.layout_template` (host layout / bridge) | Ensure the template defines `stylesheets` and `javascripts` (and a place for `nowo_ui_content`, or a thin bridge that maps it into your `body` block). |
| Frozen overrides of `admin/index.html.twig` / `admin/show.html.twig` | Optional: change `{% extends … %}` to `@NowoHttpLogBundle/admin/base.html.twig` so host assets stack correctly. |

See [CONFIGURATION.md — web_ui](CONFIGURATION.md#web_ui) and [USAGE.md — Twig template overrides](USAGE.md#twig-template-overrides).

## 1.0.1

**No action required** for applications consuming the bundle from Packagist. Public API, configuration keys, Doctrine mappings, and admin UI behaviour are unchanged since 1.0.0.

- **Contributors / CI:** integration tests now enable Doctrine native lazy objects on PHP 8.4+ (fixes PHPUnit failures on PHP 8.5).

```bash
composer update nowo-tech/http-log-bundle
```

## 1.0.0

**First public release** of `nowo-tech/http-log-bundle`. There is no prior upgrade path.

### Install

```bash
composer require nowo-tech/http-log-bundle
```

Then:

1. Import routes (Flex recipe installs `config/routes/nowo_http_log.yaml`).
2. Update the Doctrine schema (`doctrine:migrations:diff` / `doctrine:schema:update`).
3. Configure Symfony Security for `/admin/http-log` (default role: `ROLE_ADMIN`).
4. When `async: true` (default), configure Messenger transports for persist/export/purge messages — see [CONFIGURATION.md](CONFIGURATION.md).

Full steps: [INSTALLATION.md](INSTALLATION.md).

### From this version onward

Breaking changes and migration notes for future releases will be listed under new version headings above.
