# Upgrading

This document describes how to upgrade between versions of **Http Log Bundle**.

## Table of contents

- [Unreleased](#unreleased)
- [1.1.0](#110)
- [1.0.2](#102)
- [1.0.1](#101)
- [1.0.0](#100)

## Unreleased

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
