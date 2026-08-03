# Upgrading

This document describes how to upgrade between versions of **Http Log Bundle**.

## Table of contents

- [1.0.2](#102)
- [1.0.1](#101)
- [1.0.0](#100)

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
