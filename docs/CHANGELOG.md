# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.13.2] - 2026-10-30](#1132---2026-10-30)
- [[1.13.1] - 2026-10-30](#1131---2026-10-30)
- [[1.13.0] - 2026-10-28](#1130---2026-10-28)
- [[1.12.0] - 2026-10-28](#1120---2026-10-28)
- [[1.11.0] - 2026-10-28](#1110---2026-10-28)
- [[1.10.0] - 2026-10-21](#1100---2026-10-21)
- [[1.9.1] - 2026-10-18](#191---2026-10-18)
- [[1.9.0] - 2026-10-18](#190---2026-10-18)
- [[1.8.0] - 2026-10-18](#180---2026-10-18)
- [[1.7.0] - 2026-10-14](#170---2026-10-14)
- [[1.6.0] - 2026-10-13](#160---2026-10-13)
- [[1.5.1] - 2026-10-13](#151---2026-10-13)
- [[1.5.0] - 2026-10-13](#150---2026-10-13)
- [[1.4.0] - 2026-10-13](#140---2026-10-13)
- [[1.3.1] - 2026-10-13](#131---2026-10-13)
- [[1.3.0] - 2026-10-13](#130---2026-10-13)
- [[1.2.0] - 2026-10-09](#120---2026-10-09)
- [[1.1.7] - 2026-09-27](#117---2026-09-27)
- [[1.1.6] - 2026-09-24](#116---2026-09-24)
- [[1.1.5] - 2026-08-24](#115---2026-08-24)
- [[1.1.4] - 2026-08-20](#114---2026-08-20)
- [[1.1.3] - 2026-08-19](#113---2026-08-19)
- [[1.1.2] - 2026-08-19](#112---2026-08-19)
- [[1.1.1] - 2026-08-18](#111---2026-08-18)
- [[1.1.0] - 2026-08-04](#110---2026-08-04)
- [[1.0.2] - 2026-08-04](#102---2026-08-04)
- [[1.0.1] - 2026-08-03](#101---2026-08-03)
- [[1.0.0] - 2026-08-03](#100---2026-08-03)

## [Unreleased]

## [1.13.2] - 2026-10-30

Admin action form types are FormKit types (REQ-TWIG-005 rule 11, no markup change). See [UPGRADING](UPGRADING.md#to-1132).

### Changed

- **FormKit action types (REQ-TWIG-005 rule 11):** `AbstractHttpLogActionType` (and so `HttpLogExportType`, `HttpLogPurgeType`, `HttpLogDeleteType`) is now a FormKit Type: `#[FormKitConfig('http_log')]` + `FormOptionsTrait`, like `HttpLogFilterType`. Field names, CSRF token ids (`http_log_export` / `http_log_purge` / `http_log_delete`) and the rendered hidden fields are unchanged. The `http_log` profile is documented in [CONFIGURATION](CONFIGURATION.md#form-profile-formkit).

[1.13.2]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.13.2

## [1.13.1] - 2026-10-30

Bundle routes are opt-in again: controllers are no longer autoconfigured, so a host's routing.controllers import does not expose them (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-1131).

### Fixed

- **Admin routes registered without the route import:** on Symfony >= 7.3 the `#[Route]` attributes on the
  autoconfigured `HttpLogAdminController` gave it the `routing.controller` tag, so the host's default
  `controllers: { resource: routing.controllers }` registered the `nowo_http_log_admin_*` routes even when the host
  never imported `@NowoHttpLogBundle/Resources/config/routes.yaml` (access was still checked by the controller). The
  controller is no longer autoconfigured nor matched by the bundle's service glob; its definition carries the explicit
  `controller.service_arguments` and `container.service_subscriber` tags. The routes exist only through the
  documented import (REQ-SF-004 rule 7). Regression test with a kernel that only loads `routing.controllers`.

[1.13.1]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.13.1

## [1.13.0] - 2026-10-28

Performance: the admin list counts and paginates at most 10 000 entries; exports skip the per-batch COUNT. See [UPGRADING](UPGRADING.md#to-1130).

### Performance

- **Bounded admin list.** Every list page ran a full `COUNT` and an unbounded `OFFSET` over the whole log. The list now
  counts and paginates at most 10 000 matching entries (`HttpLogEntryRepository::MAX_LISTED`, header shows
  "10000+ entries"; narrow the filters to reach older ones) and clamps out-of-range pages. Background / CLI exports
  are unchanged but no longer run a `COUNT` for every batch.

[1.13.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.13.0

## [1.12.0] - 2026-10-28

Security: background exports are kept in a private, expiring store and downloaded from the admin list. See [UPGRADING](UPGRADING.md#to-1120).

### Security

- **Background exports no longer leak to the shared temp directory.** Exports above `export.max_sync_rows` were
  written by the Messenger handler to `sys_get_temp_dir()/http-log-export-<uniqid>.csv|json` with the default umask
  (usually world-readable), never deleted and never offered to the admin. They contain request logs (paths,
  headers, bodies, user identifiers). They now go through `ExportFileStore`:
  - private directory `export.directory` (default `%kernel.project_dir%/var/http-log/exports`, created `0700`),
    files `0600` with random names, written as `<name>.part` and published when complete;
  - downloadable from the admin list header (`nowo_http_log_admin_export_download`, users allowed to export only,
    `Cache-Control: private, no-store`);
  - deleted after `export.ttl_hours` (default 24) by the export job and when the admin list is opened.
- `ExportHttpLogService::exportToFile()` (also used by `nowo:http-log:export`) creates files `0600`.

### Added

- `export.directory` and `export.ttl_hours` options; `ExportHttpLogMessage::$exportName` (named exports in the
  private store; the handler ignores `outputPath` for them).

[1.12.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.12.0

## [1.11.0] - 2026-10-28

Security: the admin UI refuses to compile without form CSRF protection (CsrfProtectionPass, fail closed). See [UPGRADING](UPGRADING.md#to-1110).

### Security

- CSRF fails closed: new `CsrfProtectionPass` makes the container refuse to compile when the admin UI (`web_ui.enabled: true`) is on and form CSRF protection is unavailable (`security.csrf.token_manager` or `form.type_extension.csrf` missing, or `framework.form.csrf_protection: false`). Before, the purge / delete / export actions (`AbstractHttpLogActionType` declares its own CSRF options, checked by `validateActionForm()`) accepted tokenless cross-site POSTs in that configuration. See [UPGRADING](UPGRADING.md#unreleased) and [SECURITY](SECURITY.md#threat-model).

[1.11.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.11.0

## [1.10.0] - 2026-10-21

Wave-2 REQ compliance: configurable table prefix, injected URL generator, raw-form gate in release-check. See [UPGRADING](UPGRADING.md#to-1100).

### Added

- `doctrine.table_prefix` config and `Doctrine\TablePrefixListener`: prefixes `nowo_http_log_entry` and its index names, never host entities (REQ-ORM-002).
- `make check-no-raw-html-form` in `release-check` (REQ-MAKE-002, REQ-TWIG-005).

### Changed

- `HttpLogAdminController` receives the URL generator by constructor injection (autowired router) instead of `$this->container->get('router')`; commands are registered by `#[AsCommand]` + autoconfiguration without manual `console.command` tags (REQ-SF-003).
- Rector uses the PHP 8.2 sets (package floor) with at most 2 workers (REQ-CS-003); demo `.gitignore` ignores `node_modules` / `.pnpm-store` (REQ-GITIGNORE-003).
- `.scripts/check-no-cursor-coauthor.sh` accepts git worktrees (REQ-GIT-001).
- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README documentation list order and labels, CONTRIBUTING Cursor Agent settings and post-release-commit trailer check, canonical bug-report template, tailored Spec Kit constitution, doc TOCs, Spec Kit inventory refresh (50/50, UiKit page contract).

[1.10.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.10.0

## [1.9.1] - 2026-10-18

Requires UiKit ^2.2 (table partial, scripts partial in the demo); filter placeholders and literal choices translated once. See [UPGRADING](UPGRADING.md#to-191).

### Changed

- Requires `nowo-tech/ui-kit-bundle` **^2.2** (default-theme polish: table header hierarchy, striped rows, subtle
  badges, grid filter row, solid destructive confirm button with a close button). See [UPGRADING](UPGRADING.md#unreleased).
- Entry page: the header / query-parameter tables render through the UiKit 2.2 `table` partial (`View\Table` from
  `AdminPages::keyValueTable()`, also exposed as `payload.tables`) instead of a hand-written table; the section title
  is the table's accessible name.
- Demo: the host layout prints the UiKit runtimes through the UiKit `scripts` partial (the hand-kept list lacked
  `dialog` and `theme`).

### Fixed

- List: entries logged within the same second are ordered newest id first (stable pages; the order was undefined).
- **Missing / double translations (profiler):** the filter's HTTP method and body-type choices are literals
  (`choice_translation_domain: false`), no longer looked up in `NowoHttpLogBundle`. The filter placeholders moved from
  the page model (`FilterField`, translated there and again by the form theme; `404`, `/api/`, `app_` were looked up
  as messages) to the `HttpLogFilterType` field `attr` as catalogue keys translated once (`filter.q_placeholder`, new
  `filter.status_code_placeholder`, `filter.path_placeholder`, `filter.route_name_placeholder`, 7 locales). A contract
  test renders every admin page with a `DataCollectorTranslator` and asserts no missing message.

[1.9.1]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.9.1

## [1.9.0] - 2026-10-18

`/_wdt/` and `/_profiler/` ignored by default, entry URL keeps the port; readable entry page (badges, header tables, pretty JSON bodies) and grouped list filters. See [UPGRADING](UPGRADING.md#to-190).

UI polish after the UiKit page-contract migration: readable entry page, grouped filters, WebProfiler requests no longer recorded by default. See [UPGRADING](UPGRADING.md#to-190).

### Changed

- **Default ignored paths:** `/_wdt/` and `/_profiler/` (Symfony WebProfiler toolbar and profiler) join the kit defaults merged into `ignore_path_prefixes` while `ignore_default_path_prefixes` is `true` (default), so dev traffic of the toolbar no longer floods the log. Set `ignore_default_path_prefixes: false` to record them again (then list the kit paths you still want skipped). The demo drops its explicit `ignore_path_prefixes`.
- **Entry page:** Method and Status as badges (the list's `badge_neutral` / status slots), request ID, URL and route in the UiKit `code` slot; request / response headers and query parameters as name | value tables (list values joined, redacted values stay `[REDACTED]`, muted); JSON bodies pretty-printed; bodies in a bounded, scrollable code block that wraps long lines (UiKit `nowo-ui-code-block--wrap`) and ends on a whole line. New stylesheet `bundles/nowohttplog/css/nowo-http-log-admin.css` (entry page only; run `assets:install`). Template variable `payload` (`AdminPages::payload()`); without it the 1.8 JSON blocks render.
- **Filters:** fields ordered search, method, status, client IP / path, route, body type / created from–to, sized for the UiKit filter row; method and body type selects start with "Any"; placeholders for search, status, path and route; created from / to are native date-time pickers (`datetime-local`, minute precision). New translation keys `filter.any`, `filter.q_placeholder`, `admin.field.name`, `admin.field.value` (all 7 locales).
- **List:** paths use the UiKit `code` slot instead of a bare `<code>`.

### Fixed

- **Entry URL lost the port:** the recorder stored `Request::getHost()`; it now stores `getHttpHost()`, so non-default ports are kept (`http://127.0.0.1:8000/api/echo`). Entries recorded before keep their port-less host.

[1.9.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.9.0

## [1.8.0] - 2026-10-18

Admin pages on the UiKit 2.1 standard page contract (REQ-UI-003): list and show scaffolds, one host theme, deprecated chrome keys bridged into `nowo_ui_kit.theme.bundles`. See [UPGRADING](UPGRADING.md#to-180).

### Added

- `View\AdminPages`: builds the UiKit view models of the admin pages (`ListPage`, `ShowPage`).
- `DependencyInjection\LegacyThemeBridge`: prepends the deprecated 1.x chrome keys into `nowo_ui_kit.theme.bundles.NowoHttpLogBundle`.
- `docs/TEMPLATES.md` (pages → scaffolds, data blocks) and the page contract test (`tests/Integration/PageContractTest.php`, UiKit `PageContractTestCase`).
- Status badges per status class, breadcrumbs on the entry page, responsive (card) list on narrow screens, clear-filters link.
- Demo: UiKit admin layout bridge, admin smoke test, WebP screenshots in `docs/images/demo/`.

### Changed

- Requires `nowo-tech/ui-kit-bundle` **^2.1** (CSRF-safe `FormView` header actions, `code_block` slot).
- `admin/index.html.twig` extends `@NowoUiKit/page/list.html.twig` (header actions, filter row, table, row actions, confirm dialog, pagination, empty state from UiKit); `admin/show.html.twig` extends `@NowoUiKit/page/show.html.twig` (summary section, payload cards in `nowo_ui_show_after`).
- `web_ui.layout_template` defaults to `null` (UiKit admin layout, `nowo_ui_kit.layouts.admin`); the 1.x value `@NowoHttpLogBundle/layout.html.twig` is read as `null`.
- Purge and delete confirm in the UiKit `<dialog>` (no inline `confirm()`); export / purge / delete are `FormView`-backed UiKit POST actions (same forms, flat `_token`, same CSRF ids; export posts `format` and the criteria as hidden fields, as in 1.x).
- Export / purge / delete actions are hidden from users the access checker denies.
- Payload `<pre>` blocks of the entry page use the UiKit `code_block` theme slot (`nowo-ui-code-block`; the class `nowo-http-log-code` stays on the element); the deprecated `web_ui.css_classes.code` is bridged to `code_block`.
- The `http_log` FormKit profile no longer adds `mb-2` row classes (filter rows sit in the UiKit filter row).
- Removed: `layout.html.twig` (Bootstrap CDN), `admin/base.html.twig`, `admin/_pagination.html.twig`, `admin/_macros.html.twig`, the Twig globals `nowo_http_log_layout_template` / `nowo_http_log_css_framework`, the 1.x page blocks other than `nowo_http_log_section_*` / `nowo_http_log_detail_sections` (see UPGRADING "Template contract").

### Deprecated

- `web_ui.css_framework`, `web_ui.icon_set` (use `nowo_ui_kit`; still seeded when set here), `web_ui.templates.filter` / `templates.pagination`, `web_ui.css_classes`, `web_ui.attributes` (bridged into `nowo_ui_kit.theme.bundles.NowoHttpLogBundle`), `web_ui.ui_kit_stylesheet`, `web_ui.header_actions_in_layout`, `web_ui.confirm_attributes`, `web_ui.ui_kit_row_actions` (no effect). Removed in 2.0.
- `admin/_filter.html.twig` (BC partial for `web_ui.templates.filter` with the `nowo_http_log_filter_*` blocks), `View\AdminChrome` and the `nowo_http_log.web_ui.chrome` parameter.

[1.8.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.8.0

## [1.7.0] - 2026-10-14

Kit ignore paths and keyset purge (spec [003](../specs/003-kit-ignore-paths-keyset-purge/spec.md)).

### Added

- `ignore_default_path_prefixes` (default `true`): the kit-internal paths `/_nowo/` (HotReloadBundle client / shared worker), `/sw.js` and `/manifest.webmanifest` (PwaBundle) are skipped by default, merged with `ignore_path_prefixes` (host entries first, duplicates removed). Set it to `false` to record them.
- `retention.purge_batch_size` (default `1000`, min `1`): rows deleted per statement by the purge.

### Changed

- `HttpLogEntryRepository::deleteOlderThan()` deletes in keyset batches (`id > :afterId ORDER BY id LIMIT n`, then `DELETE … WHERE id IN (…)`, until a short batch) instead of one unbounded `DELETE`, so purging a large log no longer locks the table or writes one huge binlog event. New optional argument `int $batchSize = 1000`; the return value (rows deleted) and the purge command / message output are unchanged.
- The criteria purge (admin UI with filters) uses `retention.purge_batch_size` for its id batches (was a fixed 500).

[1.7.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.7.0

## [1.6.0] - 2026-10-13

### Fixed

- Admin filter form no longer renders raw FormKit convention keys: the seeded FormKit profile `http_log` now sets `convention_requires_translation: true`, so `http_log_filter.<field>.help` / `.placeholder` are only applied when the host translates them (previously every filter field showed e.g. `http_log_filter.method.help` under the input and as its placeholder). With FormKit 2.9+ the optional `method` / body-type selects also no longer carry a meaningless `placeholder` attribute; they keep their empty "any" option.

### Changed

- Requires `nowo-tech/form-kit-bundle` **^2.10** (was ^2.0) for `convention_requires_translation` and the 2.9 ChoiceType placeholder semantics.
- Tests: admin golden files pin the bundle chrome only (filter rows rendered through a label-only stub theme); the real filter widgets are asserted as invariants, so FormKit / Symfony Form markup changes no longer break the goldens.

[1.6.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.6.0

## [1.5.1] - 2026-10-13

### Fixed

- The recorder now always ignores the admin UI path (`web_ui.path_prefix`, when `web_ui.enabled` is true) in addition to `ignore_path_prefixes`, so a custom prefix no longer makes the admin UI record its own requests (e.g. when `ignore_routes` was overridden without `nowo_http_log_*`). The match is segment-aware: `/ops/logs` skips `/ops/logs` and `/ops/logs/42`, not `/ops/logsearch`. Docs (CONFIGURATION, UPGRADING) updated accordingly.

[1.5.1]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.5.1

## [1.5.0] - 2026-10-13

### Security

- `HttpLogEntryRepository::findOneBy()` no longer interpolates criteria / order-by keys into DQL: field names are checked against the entity metadata and unknown ones throw `InvalidArgumentException` (a caller-controlled key such as `id = 1 OR 1 = 1 OR e.id` could rewrite the query). `null` criteria now use `IS NULL` and list values `IN (...)`, like Doctrine's own repository.

### Fixed

- `web_ui.path_prefix` is now the prefix of the admin routes (it was only exposed to Twig; routes were hard-coded to `/admin/http-log`). A trailing `/` is trimmed; `/` alone and values containing `%` are rejected.
- `export.formats` is enforced: only enabled formats get an export button, the export action answers 400 for a disabled format and `nowo:http-log:export` exits with `INVALID`. Values are restricted to `csv` / `json` and at least one is required.
- `web_ui.icon_set` is now a real configuration key (default `bootstrap-icons`); it was read when seeding NowoUiKitBundle but could not be set.

### Changed

- Requirements: Symfony `^7.4 || ^8.0` (was `^7.0`); CI no longer runs a 7.0 cell (it resolved to 7.4 anyway).

[1.5.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.5.0

## [1.4.0] - 2026-10-13

### Security

- Request/response bodies are now redacted **before** `max_body_bytes` truncation; previously a truncated JSON body failed to decode and was stored raw with its secrets. A JSON-looking body that cannot be parsed is stored as `[REDACTED-UNPARSEABLE]`.
- `application/x-www-form-urlencoded` bodies and `multipart/form-data` text fields are now redacted by field name (`query_params` + `json_paths` leaf keys); they were stored verbatim (e.g. Symfony form-login `_password`).
- Default redaction keys now include `_password`, `_token`, and `_csrf_token` (query params / form fields and `*.` JSON paths).
- **Behaviour change:** `security.access_roles: []` now denies everyone (fail closed) instead of granting access to all users including anonymous ones. Use `allow_unauthenticated: true` or a custom `access_checker` to open the admin UI deliberately.

### Fixed

- Admin purge rejects a negative or non-integer `older_than_days` with HTTP 400 (a negative value produced a future cutoff via `--N days`); `PurgeHttpLogService::purgeByRetention()` rejects negative overrides.

[1.4.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.4.0

## [1.3.1] - 2026-10-13

### Changed

- Repository: `.twig-cs-fixer.cache` is now git-ignored.

[1.3.1]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.3.1

## [1.3.0] - 2026-10-13

Admin chrome hooks (spec [002](../specs/002-admin-chrome-hooks/spec.md)): hosts theme the admin pages from config, blocks and partial paths instead of forking them. Default output is unchanged (whitespace-equivalent to 1.2.0, golden-file tests) except the pagination links, which now keep the filters (see Fixed).

### Added

- **Twig blocks** around every chrome region of `admin/index.html.twig` (page header / intro, filter, toolbar, summary, header actions and each export / purge action, table, table head, table row, row actions, empty state, pagination), `admin/show.html.twig` (page header / intro, header actions, details, each detail section, delete form) and `admin/_filter.html.twig` (panel, fields, actions).
- **`web_ui.templates`** (`index`, `show`, `filter`, `pagination`): page and partial paths. The pagination partial (new `admin/_pagination.html.twig`) receives a UiKit `_pagination` compatible contract (`pagination`, `item_count`, `route`, `query`, …).
- **`web_ui.css_classes`**: strict CSS class map, defaults equal to the shipped classes; `null` keeps the UiKit macro for `css_framework` (`ui.btn`, `ui.row_actions`), `''` drops the attribute; optional `results` / `show` wrappers.
- **`web_ui.attributes`**: extra HTML attributes (e.g. `data-testid`) on filter, results, toolbar, table, detail and delete-form elements.
- **`web_ui.ui_kit_stylesheet`** (default `true`), **`web_ui.header_actions_in_layout`** (default `false`; the bundle layout renders `block('nowo_http_log_header_actions')` in its header), **`web_ui.confirm_attributes`** (CSP-friendly replacement for the inline `onsubmit` confirm; `{message}` placeholder), **`web_ui.ui_kit_row_actions`** (UiKit `_row_actions` partial).
- `@!NowoHttpLogBundle` Twig namespace (bundle originals) so `templates/bundles/NowoHttpLogBundle/` overrides can extend the page they replace and override only blocks.
- `Nowo\HttpLogBundle\View\AdminChrome` (defaults + `fromWebUi()`); the controller passes the resolved chrome to Twig as `nowo_http_log_ui`.
- Translation keys `flash.export_queued`, `flash.purged`, `flash.deleted` in all seven catalogues.
- Docs: USAGE "Customising the admin chrome" (blocks, thin forks, header actions bridge, pagination contract, Symfony Beacon per-fork verdict and config); CONFIGURATION "Admin chrome hooks (1.3.0)".

### Changed

- Admin flash messages are translated through the `NowoHttpLogBundle` catalogue (English text unchanged; English fallback when no translator is available).
- German, French, Italian, Dutch and Portuguese catalogues: the admin strings are now translated (they still held English placeholders).
- The container parameter `nowo_http_log.web_ui` escapes `%` in string values (so class names such as `w-[50%]` survive parameter resolution).
- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.

### Fixed

- **Pagination kept dropping the filters:** list pagination links carried the criteria as flat query keys (`?method=GET&page=2`, dates lost entirely) while the GET filter form reads `http_log_filter[...]`, so page 2 was unfiltered. Links now carry the active criteria in the form's shape (`?http_log_filter[method]=GET&http_log_filter[createdFrom]=2026-08-01T09:30&page=2`); the same `query` is passed to custom pagination partials.

### Tests

- `tests/Integration/PaginationKeepsFiltersTest.php`: real controller, filter form and templates — filtered list, follow the "next" link, page 2 is still filtered.
- `tests/Integration/AdminChromeRenderTest.php` renders the real templates (forms, routing, translations, UiKit macros) against 1.2.0 golden files and covers every hook; configuration, extension, compiler-pass and controller tests for the new options. Coverage stays at 100 % lines.

[1.3.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.3.0

## [1.2.0] - 2026-10-09

### Added

- `ignore_unrouted` (default `false`): skip requests no route matched (scanner 404s such as `/wp-login.php`, `/.env`), which `ignore_routes` cannot express.

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

### Fixed

- `HttpLogEntryRepository::findOneBy()` maps the string `$orderBy` direction straight to `SortDirection` (removes an always-false `instanceof`, PHPStan 2.3); integration test covers the ascending fallback.

### Dependencies

- `doctrine/orm` runtime constraint is now `^3.7` (was `^2.15 || ^3.0`).
- Dependabot: `doctrine/orm` 3.7.3, `igor-php/igor-php` `^0.10.0` (dev), `phpstan/phpstan-phpunit`.
- Composer refresh: `nowo-tech/form-kit-bundle` 2.6.1, `nowo-tech/ui-kit-bundle` 1.9.1; dev `phpstan/phpstan` 2.3.1, `phpstan/phpstan-symfony` 2.1.0, `rector/rector` 2.7.0.
- Demo: Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `twig/twig` 3.30.0; regenerated `config/reference.php`.

[1.2.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.2.0

## [1.1.7] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.1.7]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.1.7

## [1.1.6] - 2026-09-24

### Fixed

- **FrankenPHP worker mode (no kernel reset):** `HttpLogRecorder` detaches every persisted `HttpLogEntry` after flush, so the identity map no longer grows by one entry per logged request.
- **FrankenPHP worker mode:** the recorder resolves its EntityManager through `ManagerRegistry` and resets it when it is closed (before persisting, and after a failed flush), so one failed log insert no longer leaves the EntityManager closed for the rest of the worker's life.
- **FrankenPHP worker mode:** the user identifier is only recorded when a firewall with security enabled handled the current request, so a token left in `TokenStorage` by a previous request is no longer attributed to an anonymous request.
- **FrankenPHP worker mode:** `HttpLogEntryRepository` resolves the EntityManager from `ManagerRegistry` on every call (and resets a closed manager), so DoctrineBundle's cached repository EM is not reused after `resetManager()`.
- **Export / purge / admin:** export batches and admin list/detail detach after use; `purgeByCriteria()` selects ids only (`findIdsFiltered()`); persist handler detaches idempotency lookups.
- Fixed PHPStan findings (0 errors at level 8).

### Added

- `HttpLogEntryRepository::findIdsFiltered()` and `HttpLogEntryRepository::detachAll()`.
- Optional `HttpLogRecorder` constructor arguments `$managerRegistry` and `$security` (autowired; existing arguments unchanged).
- Worker-mode regression tests (`HttpLogRecorderWorkerModeTest`) and [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
- Specs: `FR-WORKER-001`…`005` / `SC-05` in `specs/001-baseline/spec.md`.

### Documentation

- [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md), [USAGE.md](USAGE.md), [UPGRADING.md](UPGRADING.md), README — worker mode without kernel reset.

## [1.1.5] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.1.5]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.1.5

## [1.1.4] - 2026-08-20

### Security

- **Flex recipe:** ship `when@prod` PII hardening (sampling 0.25, response bodies off, retention **14** days) and `security_nowo_http_log.yaml` access_control. Prefer **`^1.1.4`**.

## [1.1.3] - 2026-08-19

### Changed

- **Maintainer:** refresh `composer.lock` after PHP CS Fixer CI run.

## [1.1.2] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

## [1.1.1] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

## [1.1.0] - 2026-08-04

### Added

- **REQ-TWIG-004:** require `twig/extra-bundle` + `twig/string-extra`; `make check-twig-extra` in `release-check`; demos register `TwigExtraBundle`.
- **Twig-CS-Fixer:** `vincentlanglet/twig-cs-fixer`, `.twig-cs-fixer.php`, `composer twig:lint` / `twig:fix`.

### Changed

- **FormKitBundle:** depend on [`nowo-tech/form-kit-bundle`](https://github.com/nowo-tech/FormKitBundle) ^2.0. Admin form types use `FormOptionsTrait` + profile `http_log` (`#[FormKitConfig]`). Extension prepends that profile when missing; form types are tagged `form.type` so `FormOptionsMerger` is injected.
- **REQ-UI-001-kit:** Requires **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`). Admin `base.html.twig` loads `asset('css/nowo-ui.css', 'nowo_ui_kit')` and imports `@NowoUiKitBundle/macros/ui.html.twig` (flashes via `ui.flash`). Extension seeds `nowo_ui_kit` defaults from `web_ui.css_framework` (and `bootstrap-icons` when `icon_set` is unset) when the host has not configured UiKit.
- **UiKit macros:** Admin templates use `ui.btn` / `ui.row_actions` with `nowo_http_log_css_framework` instead of hard-coded Bootstrap button classes.

### Documentation

- [INSTALLATION.md](INSTALLATION.md) / [UPGRADING.md](UPGRADING.md) / [CONFIGURATION.md](CONFIGURATION.md) / [USAGE.md](USAGE.md) — FormKit, UiKit, Twig Extra, and Twig-CS-Fixer notes.

## [1.0.2] - 2026-08-04

### Fixed

- **REQ-UI-001**: admin pages extend `admin/base.html.twig`, which stacks host `stylesheets` / `javascripts` with `{{ parent() }}` when `web_ui.layout_template` points at the project layout. Demo `layout.html.twig` remains a full HTML root.

### Documentation

- [CONFIGURATION.md](CONFIGURATION.md) / [USAGE.md](USAGE.md) — host `layout_template` + base shell stacking notes.

## [1.0.1] - 2026-08-03

### Fixed

- Integration tests: enable Doctrine **native lazy objects** on PHP 8.4+ when creating the in-memory `EntityManager`, so CI passes on PHP 8.5 with Symfony 7.4 / 8.x (LazyGhost / `symfony/var-exporter` path no longer available in that matrix).

### Changed

- CI Dependabot: bump `actions/stale` from 10 to 11.

## [1.0.0] - 2026-08-03

Initial public release of **Http Log Bundle** (`nowo-tech/http-log-bundle`).

### Added

- HTTP request/response capture on kernel terminate with sampling, environment filter, and optional sub-request tracking.
- Configurable response body storage by content type (`html`, `json`, `soap`, `xml`, `text`, `binary`, `other`) with `max_body_bytes` truncation.
- Redaction for headers, query parameters, and JSON paths (safe defaults).
- Doctrine entity `HttpLogEntry` with indexed filters (route, status, IP, dates, full-text `q`).
- Secured admin UI at `/admin/http-log` (REQ-UI-001 / REQ-UI-002): list, filter, detail, CSV/JSON export, purge, CSRF on mutations.
- CLI: `nowo:http-log:export`, `nowo:http-log:purge`.
- Messenger async handlers for persist, export, and purge (`async: true` by default).
- Ignore routes (`fnmatch`) and path prefixes (admin UI excluded by default).
- Symfony Flex recipe, FrankenPHP demo (`demo/symfony8`, port 8091), Twig Inspector in demo (REQ-DEMO-001).
- Integrator docs: INSTALLATION, CONFIGURATION, USAGE, SECURITY (12.4.1 checklist + AI audit), SPEC-DRIVEN / Spec Kit baseline.
- QA: PHPUnit **100%** line coverage on `src/`, PHPStan level 8, CS-Fixer, Rector; `make validate-translations` (7 locales).
- CI workflows (PHP/Symfony matrix, demo-smoke, release sync).

### Security

- Default `security.allow_unauthenticated: false`; request body capture off; redaction of Authorization/Cookie/tokens.
- Grade **Pass (good)** / risk **Low** (static AI security review, 2026-08-03) — see [docs/SECURITY.md](SECURITY.md).
