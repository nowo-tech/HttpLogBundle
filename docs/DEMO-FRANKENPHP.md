# Demo applications with FrankenPHP (development and production)

This document describes how the bundle's demo application runs under **FrankenPHP** in Docker, and how to reproduce **development** (no cache, changes visible on refresh) and **production** (worker mode, cache enabled) configurations.

## Contents

- [Overview](#overview)
- [What the demo includes](#what-the-demo-includes)
- [Development configuration](#development-configuration)
- [Production configuration](#production-configuration)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Reproducing in another bundle](#reproducing-in-another-bundle)
- [Troubleshooting](#troubleshooting)

---

## Overview

**The `demo/` folder is not shipped when the bundle is installed** (excluded via `archive.exclude` in `composer.json`). Clone this repository to run the demo.

The demo uses:

- **FrankenPHP** (Caddy + PHP) — base image `dunglas/frankenphp:1-php8.5-alpine` (REQ-DEMO-010).
- **Docker Compose** with the app and parent bundle mounted (`../..` → `/var/http-log-bundle`).
- **Two Caddyfiles**: `Caddyfile` (production, with worker) and `Caddyfile.dev` (development, no worker).
- **Entrypoint** selects classic vs worker from **`FRANKENPHP_MODE`** (`classic` | `worker`, default **`worker`** in `.env.example`).

Demo: **Symfony 8.x** (`demo/symfony8`). From the bundle root: `make -C demo/symfony8 up` (default port **8091**).

**Smoke check (REQ-TEST-011):** `make demo-smoke` boots the demo and asserts `HTTP 200` on `/`. See `.github/workflows/demo-smoke.yml`.

| Aspect | Development | Production |
|--------|-------------|------------|
| FrankenPHP worker mode | **Off** (classic) or on | **On** (worker) |
| Twig cache | **Off** in dev | **On** in prod |
| `APP_ENV` / `APP_DEBUG` | `dev` / `1` | `prod` / `0` |

**Ports:** Set `PORT` in `demo/symfony8/.env` (default **8091**).

---

## What the demo includes

- **Symfony Web Profiler** — enabled in `dev` and `test`.
- **Http Log Bundle** (`Nowo\HttpLogBundle\NowoHttpLogBundle`) — path-mounted from the repository root.
- **SQLite** — no external database container (REQ-DEMO-006).
- **Security** — HTTP Basic `admin` / `admin` for `/admin/http-log`.

Example `config/bundles.php`:

```php
<?php

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class => ['all' => true],
    Symfony\Bundle\SecurityBundle\SecurityBundle::class => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class => ['all' => true],
    Nowo\HttpLogBundle\NowoHttpLogBundle::class => ['all' => true],
];
```

Demo routes: `/` (HTML), `/api/ping` (JSON). Each request creates an HTTP log entry viewable at `/admin/http-log`.

---

## Development configuration

Goal: changes visible on refresh without restarting the container.

1. Use **docker/frankenphp/Caddyfile.dev** (no worker).
2. Mount **docker/php-dev.ini** with `opcache.revalidate_freq=0`.
3. Use **config/packages/dev/twig.yaml** with `twig.cache: false`.
4. Set `APP_ENV=dev` and `APP_DEBUG=1`.

```bash
make -C demo/symfony8 up
```

---

## Production configuration

Use the default Caddyfile (with worker). Set `APP_ENV=prod` and `APP_DEBUG=0`. Do not mount `php-dev.ini`.

---

## Switching classic vs worker (`FRANKENPHP_MODE`)

Set in `demo/symfony8/.env`:

| Value | Behavior |
|-------|----------|
| `worker` (default) | Long-lived FrankenPHP workers |
| `classic` | One process per request; easier hot-reload |

Recreate the container after changing the value:

```bash
docker compose up -d
# or: make -C demo/symfony8 restart
```

---

## Reproducing in another bundle

See sibling Nowo bundles (for example [TwigInspectorBundle DEMO-FRANKENPHP](https://github.com/nowo-tech/TwigInspectorBundle/blob/main/docs/DEMO-FRANKENPHP.md)) for a full checklist.

---

## Troubleshooting

- **Changes not visible:** Disable worker in dev, disable Twig cache, restart container, hard-refresh browser.
- **Admin UI 401:** Use HTTP Basic credentials `admin` / `admin`.
- **No log entries:** Confirm `nowo_http_log.enabled: true` and path is not in `ignore_path_prefixes`.
- **Demo times out:** Check port **8091** availability and `docker compose logs php`.
