# Upgrading

This document describes how to upgrade between versions of **Http Log Bundle**.

## Table of contents

- [Unreleased](#unreleased)
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
