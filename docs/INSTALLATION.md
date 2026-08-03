# Installation

Install **Http Log Bundle** in a Symfony 7 or 8 application with Doctrine ORM and (recommended) Symfony Security and Messenger.

## Table of contents

- [Requirements](#requirements)
- [Composer](#composer)
- [Symfony Flex recipe](#symfony-flex-recipe)
- [Manual registration](#manual-registration)
- [Database schema](#database-schema)
- [Messenger (async capture)](#messenger-async-capture)
- [Security (admin UI)](#security-admin-ui)
- [Verify](#verify)
- [Demo application](#demo-application)

## Requirements

| Component | Version |
| --- | --- |
| PHP | 8.2 – 8.5 |
| Symfony | 7.0+ or 8.0+ |
| Doctrine ORM | 2.15+ or 3.x |
| symfony/messenger | Recommended when `async: true` (default) |
| symfony/security-bundle | Required when admin UI is enabled and `allow_unauthenticated: false` |

## Composer

```bash
composer require nowo-tech/http-log-bundle
```

## Symfony Flex recipe

When the Flex recipe is published, it copies default configuration to `config/packages/nowo_http_log.yaml`. The recipe lives in this repository at:

`.symfony/recipe/nowo-tech/http-log-bundle/1.0/`

## Manual registration

If Flex is unavailable, register the bundle in `config/bundles.php`:

```php
Nowo\HttpLogBundle\NowoHttpLogBundle::class => ['all' => true],
```

Copy the default YAML from the recipe or from `src/Resources/config/packages/nowo_http_log.yaml` in the package source.

## Database schema

The bundle registers a Doctrine mapping for `Nowo\HttpLogBundle\Entity\HttpLogEntry` (table `nowo_http_log_entry`).

Generate and run a migration:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Or update schema in development:

```bash
php bin/console doctrine:schema:update --force
```

## Messenger (async capture)

When `nowo_http_log.async` is `true` (default), entries are dispatched as `PersistHttpLogMessage`. Configure a transport:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            'Nowo\HttpLogBundle\Message\PersistHttpLogMessage': async
            'Nowo\HttpLogBundle\Message\ExportHttpLogMessage': async
            'Nowo\HttpLogBundle\Message\PurgeHttpLogMessage': async
```

Set `async: false` for synchronous persistence (useful in tests or small apps).

## Admin routes

Import the bundle routes (Flex recipe installs this automatically):

```yaml
# config/routes/nowo_http_log.yaml
nowo_http_log:
    resource: '@NowoHttpLogBundle/Resources/config/routes.yaml'
```

## Security (admin UI)


When `web_ui.enabled` is `true` and `security.allow_unauthenticated` is `false`, install and configure **symfony/security-bundle**. By default, users with `ROLE_ADMIN` may access `/admin/http-log`.

See [SECURITY.md](SECURITY.md) and [CONFIGURATION.md](CONFIGURATION.md).

## Verify

1. Send HTTP traffic through the application.
2. Open `/admin/http-log` (or your configured `path_prefix`) as an authorized user.
3. Confirm entries appear with expected headers and body policy.

## Demo application

Clone this repository and run the FrankenPHP demo:

```bash
make -C demo/symfony8 up
```

See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).
