# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/http-log-bundle` (`symfony-bundle`) |
| Audited revision | `v1.20.0` (first audit `v1.1.6`; sections below added per feature, see the kept-alive section at the end) |
| Audit date | 2026-09-24, refreshed 2026-11-12 |
| Method | Manual review of every file under `src/` (terminate subscriber, recorder, capture policy, redactor, classifier, export/purge services, Messenger handlers, admin controller, repository, forms, commands, DI extension, compiler pass, `Resources/config`) |
| **Verdict** | ✅ **Viable under scenario B** — bundle services are stateless; log entries are written with DBAL inserts, so no entity enters the identity map and nothing of the application is flushed (1.16.2); the repository resolves the EntityManager through `ManagerRegistry` on every call (and resets it only when it is dedicated to the bundle); the user is only recorded when a secured firewall handled the current request; admin/export/purge do not leave hydrated rows behind. |
| Remediation (2026-09-23 / 2026-09-24) | W-01, W-02, W-03, W-05, W-07 resolved in `HttpLogRecorder`, `HttpLogEntryRepository`, `ExportHttpLogService`, `PurgeHttpLogService`, `HttpLogAdminController`, `PersistHttpLogHandler`; W-04, W-06 accepted. Regression tests: `tests/Integration/HttpLogRecorderWorkerModeTest.php`, `tests/Unit/Service/HttpLogRecorderTest.php`, `tests/Integration/HttpLogEntryRepositoryTest.php` |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
  - [W-01 — Each logged request leaves an entity in the shared EntityManager (Medium)](#w-01--each-logged-request-leaves-an-entity-in-the-shared-entitymanager-medium)
  - [W-02 — A failed log flush closes the EntityManager for the rest of the worker's life under B (Medium)](#w-02--a-failed-log-flush-closes-the-entitymanager-for-the-rest-of-the-workers-life-under-b-medium)
  - [W-03 — User identifier is read from `TokenStorage` at terminate time (Medium)](#w-03--user-identifier-is-read-from-tokenstorage-at-terminate-time-medium)
  - [W-04 — Synchronous log write runs on the worker thread (Low)](#w-04--synchronous-log-write-runs-on-the-worker-thread-low)
  - [W-05 — Sync export and purge keep all loaded rows in the identity map (Low)](#w-05--sync-export-and-purge-keep-all-loaded-rows-in-the-identity-map-low)
  - [W-06 — Queued export files are never removed (Low)](#w-06--queued-export-files-are-never-removed-low)
  - [W-07 — Repository cached a closed EntityManager after `resetManager()` (Medium)](#w-07--repository-cached-a-closed-entitymanager-after-resetmanager-medium)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)
- [HL-1 usage capture (1.15.0)](#hl-1-usage-capture-1150)
- [HL-1 named channels and rollup (Unreleased)](#hl-1-named-channels-and-rollup-unreleased)
- [GDPR ports (Unreleased)](#gdpr-ports-unreleased)
- [Framework-free ports and the entry-recorded event (Unreleased)](#framework-free-ports-and-the-entry-recorded-event-unreleased)

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Every bundle service only has `private readonly` constructor properties |
| Static properties / `static` locals | ✅ | None; only static closures and `HttpLogCapture::fromArray()` |
| `ResetInterface` / `kernel.reset` coverage | ✅ | The first-generation services need no reset. Later services that hold per-boundary state clear it themselves: `HttpLogContext` (frames popped in `finally`), `HttpLogBuffer` and `HttpLogErasedSubjects` (flushed / cleared at the terminate boundary), `MailerCaptureListener` (each `WeakMap` entry is unset when its send is recorded; there is no terminate hook, and an entry of a send that never completes vanishes with its message). `RouteTemplateMap` is different: a load-once cache of read-only route templates that is only dropped by `kernel.reset`; it holds no request data, so keeping it for the worker's lifetime is safe (see the HL-1 sections) |
| Request / user / locale captured in services | ✅ | Start time and request id go in request attributes; the user identifier is only read from `TokenStorage` when a firewall with security enabled handled the current request (W-03) |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None used |
| Doctrine / EntityManager | ✅ | Manager resolved per call through `ManagerRegistry` (recorder + repository); entries written with DBAL inserts (never flushed, W-01, W-02); a closed manager is reset only when dedicated to the bundle (W-07); admin/export rows detached after use. Clearing application entities between requests stays the application's responsibility |
| Output, headers, `exit`, shutdown functions | ✅ | None; export returns a `Response` |
| Resources (files, sockets, cURL) held open | ✅ | Export streams are closed in `finally` blocks |
| Memory growth across requests | ✅ | Log entries, admin pages, and export batches are detached; purge loads ids only (W-01, W-05, W-07) |
| Blocking I/O and timeouts | ⚠️ Low (accepted) | Synchronous DB write per request on the worker thread when no async transport is routed (W-04) |
| Third-party static state | ✅ | FormKit `FormOptionsTrait::withBuilder()` restores its bound builder in `finally`; no global state |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

Worker demo: `demo/symfony8/docker/frankenphp/Caddyfile` declares a `worker` block (line 15); `Caddyfile.dev` runs classic mode.

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `EventSubscriber\HttpLogSubscriber` (`kernel.request` 1024 / `kernel.terminate` -1024) | yes | none (`readonly` config) | ✅ | ✅ |
| `Service\HttpLogRecorder` | yes | none (`readonly`); writes through the manager from `ManagerRegistry`, reads `TokenStorageInterface` only behind a secured firewall | ✅ | ✅ (W-01..W-03 resolved) |
| `Service\CapturePolicy` | yes | none | ✅ | ✅ |
| `Service\HttpLogRedactor` | yes | none | ✅ | ✅ |
| `Service\ContentTypeClassifier` | yes | none (no properties) | ✅ | ✅ |
| `Service\ExportHttpLogService` | yes | none (`readonly` optional closures, null in the container) | ✅ | ✅ (W-05 resolved) |
| `Service\PurgeHttpLogService` | yes | none | ✅ | ✅ (W-05 resolved) |
| `Repository\HttpLogEntryRepository` | yes | none beyond resolving EM from registry per call | ✅ | ✅ (W-07 resolved) |
| `MessageHandler\PersistHttpLogHandler` | yes | none | ✅ | ✅ (delegates to `persistCapture()`; detaches idempotency lookups) |
| `MessageHandler\ExportHttpLogHandler`, `PurgeHttpLogHandler` | yes | none | ✅ | ✅ |
| `Controller\HttpLogAdminController` | yes | none (`readonly` references and config) | ✅ | ✅ (detaches list/detail after render; W-06 accepted for queued export files) |
| `nowo_http_log.access_checker.default` (`ConfigurableHttpLogAccessChecker`) | yes | none (`final readonly`) | ✅ | ✅ |
| 5 form types (`Form\*Type`) | yes | FormKit trait properties, restored after each build | ✅ | ✅ |
| 2 console commands | CLI only | none | N/A | N/A |

`HttpLogCapture` is a `final readonly` value object created per call. Twig globals are registered at compile time through `prependExtensionConfig('twig', ...)` (`src/DependencyInjection/NowoHttpLogExtension.php:63-70`), not per request.

## Findings

### W-01 — Each logged request leaves an entity in the shared EntityManager (Medium)

- **Where:** `src/Service/HttpLogRecorder.php:124-129` (`persistCapture()`: `persist()` + `flush()`), called from `record()` (`:46-64`), which runs in `HttpLogSubscriber::onKernelTerminate()` (`src/EventSubscriber/HttpLogSubscriber.php:69-95`). With the default `async: true` and no Messenger transport routed for `PersistHttpLogMessage`, the message is handled synchronously and `PersistHttpLogHandler` (`src/MessageHandler/PersistHttpLogHandler.php:26-41`) calls the same method in the worker.
- **Worker impact:** the recorder never calls `clear()` or `detach()`. Under **A**, DoctrineBundle's `kernel.reset` clears the identity map at the start of the next request, so this is fine. Under **B**, every logged request adds one `HttpLogEntry`, holding redacted headers, query parameters and up to `max_body_bytes` (default 64 KiB) of request and response body each, to an identity map that is never cleared: memory grows without limit, and every later `flush()` has to compute change sets over a larger unit of work. Also, `flush()` writes the **whole** unit of work of the shared EntityManager. Under B that includes managed entities that an earlier request (possibly another user's) changed but deliberately did not flush, so they are written in a later request.
- **Recommendation:** run with `services_resetter` enabled. In the bundle, detach the entry after flushing (`$entityManager->detach($entry)`), or persist through a dedicated EntityManager or a DBAL insert so logging never flushes application entities. Integrators on scenario B should route `PersistHttpLogMessage` to a real async transport.
- **Status:** Resolved — `HttpLogRecorder::persistCapture()` (`src/Service/HttpLogRecorder.php`) detaches the entry in a `finally` block after `flush()`, so the identity map does not grow per request. Residual (accepted): the `flush()` still writes the whole unit of work of the manager that maps `HttpLogEntry`; under scenario B clearing application entities between requests remains the application's responsibility (the bundle never calls `clear()` on the app's manager). Map `HttpLogEntry` to a dedicated entity manager or route `PersistHttpLogMessage` to an async transport to isolate it.
  **Update 1.16.2:** the residual is gone. `persistCapture()` writes the entry with one DBAL `INSERT` on the manager's connection (no `persist()`, no `flush()`), so no entry enters the identity map and the host's unflushed changes are never written by a capture. Regression tests: `tests/Integration/HostEntityManagerIsolationTest.php`, and `tests/Integration/WorkerModeKeptAliveKernelTest.php` (one kept-alive kernel, 1,200 requests through handle + terminate and 1,200 recordings alternating two users: flat memory, empty unit of work, every row carries its own request's client and user).

### W-02 — A failed log flush closes the EntityManager for the rest of the worker's life under B (Medium)

- **Where:** `src/Service/HttpLogRecorder.php:58-63` catches every `Throwable` from `persistCapture()` and only logs it.
- **Worker impact:** when the insert fails (for example a value longer than a column, a database outage or a deadlock), Doctrine closes the EntityManager. The exception is swallowed, so nothing restarts the worker. Under **A**, DoctrineBundle replaces the closed manager on the next request. Under **B**, the default EntityManager stays closed: every later log write fails, and so does every Doctrine operation of the host application on that EntityManager ("The EntityManager is closed") until the worker is recycled. One bad request can take down the whole worker.
- **Recommendation:** keep `services_resetter` enabled. For scenario B, call `ManagerRegistry::resetManager()` in the `catch` block when the manager is no longer open, or use a dedicated entity manager for logs.
- **Status:** Resolved — `HttpLogRecorder` takes an optional `ManagerRegistry` (autowired), resolves the manager with `getManagerForClass(HttpLogEntry::class)` per call, resets it when it is closed before persisting, and resets it in the `catch` of a failed flush before rethrowing (`src/Service/HttpLogRecorder.php`). `record()` still logs and swallows the error, so `kernel.terminate` never throws.
  **Update 1.16.2:** a DBAL insert never closes the manager, so the recorder no longer resets anything (the manager may be the host's).

### W-03 — User identifier is read from `TokenStorage` at terminate time (Medium)

- **Where:** `src/Service/HttpLogRecorder.php:160-177` (`resolveUserIdentifier()`), called from `buildCapture()` (`:112`) when `capture.user` is enabled (the default).
- **Worker impact:** under **A** the token storage is reset between requests, so the value belongs to the current request. Under **B**, Symfony's token storage is not reset. On requests that do not go through a firewall that replaces the token (routes outside any firewall, `security: false` patterns, some stateless firewalls with no credentials), the token from a previous request is still there. The log entry then records **another user's identifier** for an anonymous request. That corrupts the audit trail and exposes one user's identifier next to another client's IP and request data in the admin UI.
- **Recommendation:** keep `services_resetter` enabled. Under B, set `capture.user: false`, or have the bundle resolve the user on `kernel.request`/`kernel.response` and store it in a request attribute (as it already does for start time and request id), instead of reading `TokenStorage` during terminate.
- **Status:** Resolved — `HttpLogRecorder::resolveUserIdentifier()` only reads the token when `Security::getFirewallConfig($request)` reports a firewall with security enabled for the current request (fallback without `Security`: the `_firewall_context` request attribute). Requests outside any firewall or under `security: false` are logged without a user. Residual framework responsibility: a stateless firewall that receives no credentials does not replace a stale token under B; that leak affects the application's own access checks too and must be solved by keeping `services_resetter` (or resetting `security.token_storage`) between requests.

### W-04 — Synchronous log write runs on the worker thread (Low)

- **Where:** `src/EventSubscriber/HttpLogSubscriber.php:94` → `HttpLogRecorder::record()`.
- **Worker impact:** `kernel.terminate` runs after the response is sent, but the worker thread is not free for the next request until the database insert (and, for redacted JSON bodies, `json_decode`/`json_encode` of up to `max_body_bytes`) finishes. No bundle-level timeout exists; a slow database directly lowers worker throughput. This is not a state leak.
- **Recommendation:** route `Nowo\HttpLogBundle\Message\PersistHttpLogMessage` to an async Messenger transport and run `messenger:consume` with `--memory-limit` / `--time-limit`. Use `sampling_rate` and `ignore_path_prefixes` for high-traffic endpoints.
- **Status:** Accepted — throughput concern, not a state leak; the async transport is the supported mitigation.

### W-05 — Sync export and purge keep all loaded rows in the identity map (Low)

- **Where:** `src/Service/ExportHttpLogService.php:107-129` / `:132-155` (`findFiltered()` in batches of 500, no `clear()`), `exportToString()` (`:49-70`) buffers the whole export in memory; `src/Service/PurgeHttpLogService.php:54-70` loads batches of entities only to collect their ids. Sync export is capped by `export.max_sync_rows` (default 1000, `src/Controller/HttpLogAdminController.php:136-145`).
- **Worker impact:** admin-only and bounded per request, but under **B** those managed entities (with their bodies) stay in memory until the worker restarts, adding to W-01.
- **Recommendation:** keep `max_sync_rows` low; in the bundle, clear or detach after each batch, and select only ids in the purge loop.
- **Status:** Resolved — `ExportHttpLogService` calls `HttpLogEntryRepository::detachAll()` after each batch; `PurgeHttpLogService::purgeByCriteria()` uses the new `HttpLogEntryRepository::findIdsFiltered()` (scalar ids, no hydration).

### W-06 — Queued export files are never removed (Low)

- **Where:** `src/Controller/HttpLogAdminController.php:138-139` builds `sys_get_temp_dir()/http-log-export-<uniqid>.<ext>`; `ExportHttpLogHandler` (`src/MessageHandler/ExportHttpLogHandler.php:22-32`) writes it and only logs the path.
- **Worker impact:** no in-process state, but files accumulate in the temp directory of the long-lived container, which is often not cleaned while the worker runs.
- **Recommendation:** configure a dedicated export directory with cleanup, or delete files after download.
- **Status:** Accepted — the file is the output of the queued export and there is no download endpoint that could delete it; temp-directory retention stays an operator concern (tmp cleanup / container restart).

### W-07 — Repository cached a closed EntityManager after `resetManager()` (Medium)

- **Where:** `ServiceEntityRepository` / DoctrineBundle proxy caches the EntityManager (or an inner repository) after the first call. `HttpLogRecorder` resets a closed manager via `ManagerRegistry`, but `HttpLogEntryRepository` kept using the closed instance for admin/export/purge/`findOneBy` idempotency checks.
- **Worker impact:** under **B**, the first failed log flush that closes the EM, followed by a successful `resetManager()` in the recorder, still left the repository talking to the closed manager → admin UI and later Doctrine operations on that repository fail until the worker restarts.
- **Status:** Resolved — `HttpLogEntryRepository` overrides `createQueryBuilder` / `find` / `findOneBy` / `getEntityManager` to resolve (and reset if closed) through `ManagerRegistry` on every call. Admin index/show detach after render; persist handler detaches idempotency lookups.

No other findings. Good patterns: request start time and request id are stored in request attributes (`src/EventSubscriber/HttpLogSubscriber.php:58-67`), not in the subscriber; redaction and capture rules are pure functions of compile-time config.

## Usage recommendations in worker mode

- Scenario B is supported by the bundle itself since the 2026-09-24 remediation (W-01…W-03, W-05, W-07). Keeping `services_resetter` active (scenario A) is still recommended because Doctrine's and Security's own state (application entities in the identity map, tokens on stateless firewalls) is framework-owned.
- Prefer `async: true` **with** a real Messenger transport for `PersistHttpLogMessage`; the consumer process then gets Messenger's own EntityManager clearing between messages.
- If you run without reset, the application must clear its own EntityManager between requests; recycling workers (`max_requests` in the FrankenPHP `worker` block) is still a good safety net.
- Do not decorate `HttpLogRecorder` with a buffer that collects captures in a property to flush later; under B that buffer would grow and mix requests.
- Custom `security.access_checker` services must stay stateless.

## Re-audit triggers

Re-run this audit when a change adds: properties to `HttpLogRecorder` or the subscriber (buffers, batching), a dedicated entity manager or DBAL writer, new Doctrine listeners, capture of session or user data beyond the identifier, or any use of `$_SERVER` / `getenv()` at runtime.

## HL-1 usage capture (1.15.0)

| Service | State | Reset |
| --- | --- | --- |
| `HttpLogContext` | stack of frames (tenant, project, message id) | frames always popped in `finally`; `kernel.reset` empties the stack |
| `HttpLogBuffer` | pending captures, overflow counter, suspension depth | `kernel.reset` flushes, then clears |
| `MailerCaptureListener` | `WeakMap` of started sends | entries vanish with their message; `kernel.reset` replaces the map |
| `LoggingHttpClient` | none (the decorated client only) | per-call state lives in the response's passthru closure (`OutboundCall`, not a service) |
| `UsageEntryWriter` | none | DBAL insert, no entity kept: the identity map never grows |

Worker tests run 1 000 sequential requests / messages on the same instances: no frame left, no tenant leaking into the
next request, an empty buffer after each boundary, flat memory, an empty `WeakMap`. Igor reports one warning for
`MailerCaptureListener` (it calls `HttpLogBuffer::add()`, a service that has its own `kernel.reset`); reviewed, not a
leak.

## HL-1 named channels and rollup (Unreleased)

| Service | State | Reset |
| --- | --- | --- |
| `InboundRequestSubscriber` | none; pushes the request's `HttpLogContext` frame before the controller | the frame depth is kept on the request and restored at `kernel.terminate`; `kernel.reset` empties the stack anyway |
| `InboundRequestRecorder`, `InboundChannelMatcher`, `HttpLogRequestContextResolution` | none (the resolved context is a request attribute, resolved once per request) | n/a; the token is read only when a firewall with security enabled handled the request |
| `RouteTemplateMap` | the loaded route template map (read-only file contents; not kept in debug) | `kernel.reset` drops it |
| `UsageRollupStore`, `HttpLogUsageReader`, `HttpLogUsageEraser` | none | DBAL statements, no entity kept |

`ApiChannelKernelTest` runs 1 000 sequential named-channel requests (tenant on every other one) on one kernel with
`services_resetter` between them: the frame stack is empty after each, no tenant or credential reaches the next
request, memory stays flat. Igor: the remaining warning is still `MailerCaptureListener` (above); the closures run by
`Connection::transactional()` are annotated (run once, never kept).

## GDPR ports (Unreleased)

| Service | State | Reset |
| --- | --- | --- |
| `HttpLogPersonalDataErasure`, `HttpLogPersonalDataExporter`, `UserIdentifierSubjectResolver`, UserKit adapters | none (`final readonly`) | n/a |
| `HttpLogErasedSubjects` | erased user identifiers / client IPs of the current boundary, a self-service flag (capped at 10 000 values, fail closed beyond) | cleared at every main `kernel.request` (4096), after `kernel.terminate` / `console.terminate` / worker message handled or failed (-4096, after the buffer flush and the web capture), and on `kernel.reset` |

`PersonalDataKernelTest` runs 50 rounds of "log, erase in the request, log again" through `http_kernel` without the
services resetter: the erasing request is never written, the next request of the same IP always is.
`HttpLogPersonalDataPortsTest` runs export / erase for two users 1 000 times on one instance with flat memory.


## Framework-free ports and the entry-recorded event (Unreleased)

| Service | State | Reset |
| --- | --- | --- |
| `HttpLogRequestContextResolution` (now also calls `HttpRequestContextResolverInterface`) | none (`final readonly`); the `HttpRequestContextFactory` it owns holds only the configured header names | n/a |
| `HttpRequestContext` | value object built per resolution and never kept by the bundle (the resolved result stays on the request, as before) | n/a |
| `HttpLogRecorder`, `UsageEntryWriter` (dispatch `HttpLogEntryRecordedEvent`) | none (`final readonly`); the event is created per written row and never kept | n/a |
| `HttpLogContextInterface` | alias of `HttpLogContext` (same instance, same frame stack popped at `kernel.terminate`) | unchanged |

`DddHostKernelTest` runs 600 named-channel requests on one kernel with the services resetter never called, alternating
two tenants and an unauthenticated request, through a resolver that implements only the framework-free port and a
listener of the entry-recorded event: every row and every event holds only its own request's tenant and credential,
the context depth is 0 after each terminate, and memory stays within 4 MB after warm-up. Igor: no new finding (the one
existing warning is unchanged).

## Kept-alive kernel, reset false (1.20.0)

**The bundle is safe with kernel reset false** (`FRANKENPHP_RESET_KERNEL=false`, `services_resetter` never called). Every
per-request or per-boundary memo above that holds request data is released by the bundle itself (frames popped in
`finally`, buffer flushed and erased-subject set cleared at `kernel.terminate`, mail starts unset when recorded, the
request context kept on the request attributes), never by `kernel.reset` alone. The one exception is the load-once
`RouteTemplateMap` (read-only route templates, no request data), which stays for the worker's life without harm.

| Test | What it proves |
| --- | --- |
| `tests/Integration/WorkerModeKeptAliveKernelTest.php` (`aKeptAliveKernelRecordsManyRequestsWithFlatMemoryAndNoLeak`) | ONE booted kernel, 1,200 `http_kernel` handle() + terminate() cycles and 1,200 recordings alternating two users, no resetter: flat memory after warm-up, empty unit of work, every row carries its own request's client and user |
| `tests/Integration/Privacy/PersonalDataKernelTest.php`, `tests/Integration/Usage/DddHostKernelTest.php`, `tests/Integration/Usage/ApiChannelKernelTest.php` | named channels, erasure and framework-free ports on one kernel without the resetter (sections above) |

Rule 7: the bundle never clears the host's default EntityManager (log rows are DBAL inserts, a dedicated manager is only
reset when it is the bundle's own); clearing the application's own identity map between requests stays the host's job.
