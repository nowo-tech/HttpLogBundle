# Http Log Bundle

[![CI](https://github.com/nowo-tech/HttpLogBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/HttpLogBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/http-log-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/http-log-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/http-log-bundle.svg)](https://packagist.org/packages/nowo-tech/http-log-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-7.0%2B%20%7C%208.x-000000?logo=symfony)](https://symfony.com) [![GitHub stars](https://img.shields.io/github/stars/nowo-tech/HttpLogBundle.svg?style=social&label=Star)](https://github.com/nowo-tech/HttpLogBundle) [![Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen)](#tests-and-coverage)

> ⭐ **Found this useful?** Give it a star on GitHub! It helps us maintain and improve the project.

**Symfony bundle that intercepts HTTP requests and responses**, stores them with configurable body policies by content type, redaction, and a **secured admin UI** with filter, export (CSV/JSON), and purge/retention — with optional **Messenger** async persistence.

> 📋 **Compatible with Symfony 7.0+ and 8.x** (PHP 8.2+)

![FrankenPHP Friendly Worker Mode](docs/images/frankenphp-friendly.png)

This bundle is **FrankenPHP worker mode friendly**.

## What is this?

Http Log Bundle captures each HTTP exchange on kernel terminate, classifies response bodies (html, json, soap, xml, text, binary, other), applies redaction rules, and persists to Doctrine. Operators browse logs at **`/admin/http-log`**, export filtered results, and purge by retention policy.

## Features

- ✅ Kernel subscriber capture with sampling, environment filter, and sub-request toggle
- ✅ Body policy by content type with `max_body_bytes` truncation
- ✅ Redaction for headers, query parameters, and JSON paths
- ✅ Doctrine entity `HttpLogEntry` with indexes for filter queries
- ✅ Secured admin UI (filter, detail, export, purge) — REQ-UI-002
- ✅ CLI: `nowo:http-log:export`, `nowo:http-log:purge`
- ✅ Messenger async: persist, export, purge handlers
- ✅ Ignore routes (`fnmatch`) and path prefixes (admin UI excluded by default)
- ✅ Symfony Flex recipe and FrankenPHP demo (`demo/symfony8`)
- ✅ GitHub Spec Kit baseline and 100% coverage target on `src/`

## Quick start

```bash
composer require nowo-tech/http-log-bundle
```

```yaml
# config/packages/nowo_http_log.yaml
nowo_http_log:
    capture:
        response_body_by_type:
            json: true
    security:
        access_roles:
            - ROLE_ADMIN
```

```bash
php bin/console doctrine:schema:update --force
# Configure symfony/messenger when async: true (default)
```

Admin UI: **`/admin/http-log`** (requires `ROLE_ADMIN` by default).

## Development

```bash
make up
make test
make -C demo/symfony8 up
make demo-smoke
```

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Code of Conduct](CODE_OF_CONDUCT.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release process](docs/RELEASE.md)
- [Security](docs/SECURITY.md)
- [Engram](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)
- [GitHub Spec Kit](docs/SPEC-KIT.md)

### Additional documentation

- [GitHub Actions CI requirements](docs/GITHUB_CI.md)
- [Demo with FrankenPHP](docs/DEMO-FRANKENPHP.md)

## Tests and coverage

| Language | Coverage (approx.) | Command |
|----------|-------------------|---------|
| PHP | 100% on `src/` (`make test-coverage`) | `make test-coverage` |

```bash
make test
make test-coverage
make test-coverage-100
make release-check
```

Run `make test-coverage` after cloning to refresh the coverage badge and CI threshold (target: **100%** on `src/`).

## License

MIT — see [LICENSE](LICENSE).
