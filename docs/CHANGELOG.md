# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.1.1] - 2026-08-18](#111---2026-08-18)
- [[1.1.0] - 2026-08-04](#110---2026-08-04)
- [[1.0.2] - 2026-08-04](#102---2026-08-04)
- [[1.0.1] - 2026-08-03](#101---2026-08-03)
- [[1.0.0] - 2026-08-03](#100---2026-08-03)

## [Unreleased]

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
