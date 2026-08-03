# Symfony 8 demo — Http Log Bundle

Minimal Symfony 8 application running under **FrankenPHP** with SQLite and the path-mounted bundle.

## Quick start

From the **bundle root**:

```bash
make -C demo/symfony8 up
```

Default URL: **http://localhost:8091** (override with `PORT` in `.env`).

## What to try

1. Open `/` — each reload creates a new HTTP log entry.
2. Open `/api/ping` — JSON response body is stored per `response_body_by_type.json`.
3. Open `/admin/http-log` — admin UI (HTTP Basic: `admin` / `admin`).

## Commands

```bash
make -C demo/symfony8 test
make -C demo/symfony8 down
make -C demo/symfony8 shell
```

## Configuration

- Bundle config: `config/packages/nowo_http_log.yaml` (`async: false` for synchronous persistence in the demo).
- Security: in-memory `ROLE_ADMIN` user; `access_control` protects `/admin/http-log`.
- Database: SQLite at `var/data/demo.db` (no external DB container).

See [DEMO-FRANKENPHP.md](../../docs/DEMO-FRANKENPHP.md) for FrankenPHP classic vs worker mode.
