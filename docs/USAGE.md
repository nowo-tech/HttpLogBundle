# Usage

How to capture HTTP traffic, use the admin UI, export, purge, and exclude routes.

## Table of contents

- [Automatic capture](#automatic-capture)
- [Body storage policy](#body-storage-policy)
- [Redaction](#redaction)
- [Admin UI](#admin-ui)
- [Twig template overrides](#twig-template-overrides)
- [Translation overrides](#translation-overrides)
- [Export](#export)
- [Purge and retention](#purge-and-retention)
- [Ignoring routes and paths](#ignoring-routes-and-paths)
- [CLI commands](#cli-commands)
- [Async processing](#async-processing)

## Automatic capture

Once installed and enabled, the bundle registers `HttpLogSubscriber` on:

- `kernel.request` — records start time and request id (`X-Request-Id` header or generated UUID).
- `kernel.terminate` — builds the log entry and persists (sync or async).

Each stored row includes method, path, route name, status code, duration (ms), client IP, user identifier, headers, and optional bodies.

Disable globally:

```yaml
nowo_http_log:
    enabled: false
```

Restrict to specific environments:

```yaml
nowo_http_log:
    environments: [prod, staging]
```

Sample traffic:

```yaml
nowo_http_log:
    sampling_rate: 0.25
```

## Body storage policy

Request bodies are **off by default**. Enable explicitly:

```yaml
nowo_http_log:
    capture:
        request_body: true
        max_body_bytes: 32768
```

Response bodies are stored per content type:

```yaml
nowo_http_log:
    capture:
        response_body_by_type:
            json: true
            html: false
            binary: false
```

Classification uses the `Content-Type` header and body sniffing for SOAP, JSON, HTML, and XML when the header is missing.

## Redaction

Sensitive data is redacted before storage. Customize lists in [CONFIGURATION.md](CONFIGURATION.md#redaction).

Example — redact `Authorization` and JSON `*.secret`:

```yaml
nowo_http_log:
    redaction:
        headers:
            - Authorization
        json_paths:
            - '*.secret'
```

Redacted values appear as `[REDACTED]`.

## Admin UI

Default URL: **`/admin/http-log`** (configurable via `web_ui.path_prefix`).

Features:

- Paginated list with filters (method, status, route, date range, free text)
- Detail view with headers and bodies
- Export and purge actions (subject to access checker)

Access requires Symfony Security unless `security.allow_unauthenticated: true`. Default roles: `ROLE_ADMIN` (REQ-UI-002).

Custom access logic — implement `HttpLogAccessCheckerInterface` and set:

```yaml
nowo_http_log:
    security:
        access_checker: App\Security\MyHttpLogAccessChecker
```

## Twig template overrides

Application templates under `templates/bundles/NowoHttpLogBundle/` **always win** over the bundle copy for the same relative path (REQ-TWIG-001). The bundle registers its views **after** Symfony’s override directory so host files take precedence.

**Freeze rule:** once you copy a template into your app, that path is **frozen** — vendor updates to the same file will not apply until you delete or manually merge your override.

**Procedure**

1. Pick the `<subpath>` from the table below (path relative to the bundle views root).
2. Create `templates/bundles/NowoHttpLogBundle/<subpath>.html.twig` in your application.
3. Clear the cache if needed: `php bin/console cache:clear`.
4. Prefer config (`web_ui.layout_template`, `css_framework`) or surgical partial overrides before forking full pages.

Point `web_ui.layout_template` at your project layout to embed admin pages in host chrome; pages go through `admin/base.html.twig`, which stacks host `stylesheets` / `javascripts` via `{{ parent() }}` and loads UiKit CSS/macros (REQ-UI-001 / REQ-UI-001-kit). See [CONFIGURATION.md — web_ui](CONFIGURATION.md#web_ui).

| Subpath | Purpose |
| --- | --- |
| `layout.html.twig` | Default demo full HTML root (`web_ui.layout_template` default; CDN Bootstrap) |
| `admin/base.html.twig` | Intermediate shell admin pages extend (points at `web_ui.layout_template`, stacks assets with `parent()`) |
| `admin/index.html.twig` | Paginated log list, export/purge actions |
| `admin/show.html.twig` | Single entry detail view |
| `admin/_filter.html.twig` | Filter form partial on the index page |

To recover upstream UI after a full-file override, remove the app copy, clear cache, and re-apply only the customisations you still need.

## Translation overrides

Symfony loads application catalogues **before** bundle fallbacks (REQ-I18N-001).

- **Domain:** `NowoHttpLogBundle` (use this in PHP `->trans(..., domain: 'NowoHttpLogBundle')` and Twig `|trans({}, 'NowoHttpLogBundle')`).
- **Override path:** `translations/NowoHttpLogBundle.<locale>.yaml` in your app (e.g. `translations/NowoHttpLogBundle.en.yaml`).
- **Behaviour:** keys defined in the app file replace bundle strings; missing keys fall back to the bundle catalogue.

**Shipped locales:** `en`, `es`, `fr`, `de`, `it`, `nl`, `pt`.

Example — customise the admin title in English:

```yaml
# translations/NowoHttpLogBundle.en.yaml
admin.title: Request audit log
```

## Export

**Admin UI:** use the export button on the filtered list (CSV or JSON, up to `export.max_sync_rows`).

**CLI:**

```bash
php bin/console nowo:http-log:export --format=csv --output=/tmp/http-log.csv
php bin/console nowo:http-log:export --format=json
```

Large exports can be dispatched asynchronously via `ExportHttpLogMessage` when Messenger is configured.

## Purge and retention

**Retention** (`retention.days`) defines how old entries may be when purging. `null` keeps all rows until manual purge.

**CLI:**

```bash
php bin/console nowo:http-log:purge
php bin/console nowo:http-log:purge --dry-run
```

**Admin UI:** purge action on the index page (requires purge permission from access checker).

Async purge uses `PurgeHttpLogMessage`.

## Ignoring routes and paths

Skip profiler, admin, or internal routes:

```yaml
nowo_http_log:
    ignore_routes:
        - '_wdt'
        - '_profiler'
        - 'api_health_*'
    ignore_path_prefixes:
        - '/admin/http-log'
        - '/internal'
```

Route patterns use `fnmatch` (case-insensitive). The admin UI path is ignored by default so browsing logs does not flood the table.

## CLI commands

| Command | Description |
| --- | --- |
| `nowo:http-log:export` | Export entries to CSV or JSON |
| `nowo:http-log:purge` | Delete entries older than retention |

Run `php bin/console list nowo:http-log` for options.

## Async processing

With `async: true` (default), capture dispatches `PersistHttpLogMessage`. Run a worker:

```bash
php bin/console messenger:consume async -vv
```

Set `async: false` to persist in the same request (after response sent, on terminate).

Configure **retries** and a **failure transport** for production async setups — see [CONFIGURATION.md](CONFIGURATION.md#messenger-retry-and-failure-transport).

See [INSTALLATION.md](INSTALLATION.md) and [CONFIGURATION.md](CONFIGURATION.md).
