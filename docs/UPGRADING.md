# Upgrading

This document describes how to upgrade between versions of **Http Log Bundle**.

## Table of contents

- [1.0.1](#101)
- [1.0.0](#100)

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
