# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.0.0] - 2026-08-03](#100---2026-08-03)

## [Unreleased]

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
