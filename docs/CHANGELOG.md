# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.16.1] - 2026-11-09](#1161---2026-11-09)
- [[1.16.0] - 2026-11-09](#1160---2026-11-09)
- [[1.15.0] - 2026-11-09](#1150---2026-11-09)
- [[1.14.1] - 2026-11-09](#1141---2026-11-09)
- [[1.14.0] - 2026-11-03](#1140---2026-11-03)
- [[1.13.2] - 2026-10-30](#1132---2026-10-30)
- [[1.13.1] - 2026-10-30](#1131---2026-10-30)
- [[1.13.0] - 2026-10-28](#1130---2026-10-28)
- [[1.12.0] - 2026-10-28](#1120---2026-10-28)
- [[1.11.0] - 2026-10-28](#1110---2026-10-28)
- [[1.10.0] - 2026-10-21](#1100---2026-10-21)
- [[1.9.1] - 2026-10-18](#191---2026-10-18)
- [[1.9.0] - 2026-10-18](#190---2026-10-18)
- [[1.8.0] - 2026-10-18](#180---2026-10-18)
- [[1.7.0] - 2026-10-14](#170---2026-10-14)
- [[1.6.0] - 2026-10-13](#160---2026-10-13)
- [[1.5.1] - 2026-10-13](#151---2026-10-13)
- [[1.5.0] - 2026-10-13](#150---2026-10-13)
- [[1.4.0] - 2026-10-13](#140---2026-10-13)
- [[1.3.1] - 2026-10-13](#131---2026-10-13)
- [[1.3.0] - 2026-10-13](#130---2026-10-13)
- [[1.2.0] - 2026-10-09](#120---2026-10-09)
- [[1.1.7] - 2026-09-27](#117---2026-09-27)
- [[1.1.6] - 2026-09-24](#116---2026-09-24)
- [[1.1.5] - 2026-08-24](#115---2026-08-24)
- [[1.1.4] - 2026-08-20](#114---2026-08-20)
- [[1.1.3] - 2026-08-19](#113---2026-08-19)
- [[1.1.2] - 2026-08-19](#112---2026-08-19)
- [[1.1.1] - 2026-08-18](#111---2026-08-18)
- [[1.1.0] - 2026-08-04](#110---2026-08-04)
- [[1.0.2] - 2026-08-04](#102---2026-08-04)
- [[1.0.1] - 2026-08-03](#101---2026-08-03)
- [[1.0.0] - 2026-08-03](#100---2026-08-03)

## [Unreleased]

Target 1.16.2.

### Security

- **A synchronous capture no longer commits the host's pending changes (rule 6).** `HttpLogRecorder::persistCapture()`
  used to `persist()` the entry and `flush()` the EntityManager of `HttpLogEntry`, which is the host's default manager
  in a standard install. With `async: false`, or with `async: true` and `PersistHttpLogMessage` handled synchronously
  (no transport routing, or a `sync://` transport), that flush ran at `kernel.terminate` and wrote whatever the host
  had left unflushed, for example an entity bound by a form that failed validation, or an entity persisted before an
  exception was handled. The entry is now written with one DBAL `INSERT` on the connection of that manager (as the
  usage writer already did): no `persist()`, no `flush()`, the host's unit of work is untouched. Affects 1.0.0 to
  1.16.1.

### Fixed

- The recorder no longer resets a closed EntityManager (the insert does not need an open manager), and
  `HttpLogEntryRepository` resets a closed manager only when it maps the bundle's entities alone (a dedicated manager).
  A closed manager shared with the host is left for the host to reset; the repository keeps reading through it.

### Documentation

- USAGE: new section "Using it from a DDD / hexagonal application" (where the bundle sits in a hexagonal host, the ports, events and DTOs to build an anti-corruption layer on, and the known limitations).

## [1.16.1] - 2026-11-09

### Security (delta audit JA, JAd-L1 / JAd-I1)

- **Named-channel resolver guard: only the request's credential is a secret beyond exact matches.** Containment and
  prefix checks (6+ characters) now apply, at any length, only to the `Authorization` value (whole, after its scheme,
  the decoded Basic pair and password) and to the headers listed in the new `redaction.credential_headers` (default
  `[]`; list e.g. `X-Api-Key` when your API authenticates with it). Every other redacted header, query parameter and
  cookie is compared with the credential id by **exact equality only**, and never with the tenant, project or kind.
  So a client can no longer blank its own tenant UUID (or an 8-character slice of it), project or credential kind by
  sending it as `?token=` or a cookie (JAd-L1), and a 6–7-character bearer is caught by contains and prefix again
  (JAd-I1). The user identifier is checked against the request's credential only.
- BC: the `Authorization` rule is unchanged from 1.16.0; a resolver that returns a redacted header, parameter or cookie
  value verbatim as the credential id is still dropped. `HttpLogRequestContextResolution::MIN_SECRET_LENGTH` is
  deprecated (no longer used). Residual (documented): a client can still blank its own credential id by sending it
  exactly as a redacted value, or any value containing what it sends in a listed `credential_headers` header.

### Added

- `redaction.credential_headers` (default `[]`), and `HttpLogRequestContextResolution::credentialSources()`.

[1.16.1]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.16.1

## [1.16.0] - 2026-11-09

HL-1 usage capture, part 2 (contract slices S4 API channel, S5 Rollup, S6 Retention and erasure). Every new block is
off by default: a host that configures no `channels` and leaves `rollup.enabled: false` gets no new listener,
subscriber, warmer, command or schema listener, the 1.15 mapping and schema (no rollup tables) and the same payloads
and rows (BC tests on a booted kernel). Audit A2: re-verification pending.

### Added

- **Named inbound channels** (`nowo_http_log.channels`, S4): requests under path prefixes (segment-aware) or route
  patterns, minus excludes, are recorded as their own channel (`api`, `mcp`, `webhooks`…) **before** the web
  channel's ignores and sampling. Own `sampling_rate` for successes; a status >= `error_status_min` (400, Q-O7) or a
  kernel exception is always stored; own `retention_days` (purge scope), `client_ip` (`truncated` by default: IPv4
  /24, IPv6 /48, Q-O8), `user` and `rollup`. Rows are an allowlist: server event id (the client's `X-Request-Id` is
  neither stored nor a dedupe key), route path template, route name, status, `exception` error kind, duration, size,
  MIME type, IP, user, credential, tenant, project; never the raw path, query string, headers or bodies. No
  user-agent family (Q-B7).
- **Route path templates** from a cache warmer (`RouteTemplateMap`, `var/cache/<env>/nowo_http_log/route_templates.php`,
  rebuilt in debug when a routing file changes); `{unrouted}` without a route.
- **Credential / tenant resolver hook** `HttpLogRequestContextResolverInterface` (autoconfigured, tag
  `nowo_http_log.request_context_resolver`) returning `HttpLogRequestContext` (credential kind, credential database
  id, tenant key, project key). Resolved once per request, before the controller when that cannot start a lazy
  firewall's authentication (then the tenant / project become the request's `HttpLogContext` frame: outbound calls
  and mails of the controller inherit them), else at terminate. **Guard:** a value that fails the opaque-key rule or
  equals, contains or starts a credential of the request (Authorization, redacted headers and cookies, redacted query
  params) is stored NULL with a warning naming the resolver (HL-1 §4.3 case 12); same guard for the user identifier.
- **Daily rollup** (`nowo_http_log.rollup`, S5): DBAL tables `…daily` and `…event_seen` added by a `postGenerateSchema`
  listener only while enabled (DDL for MySQL / MariaDB, PostgreSQL and SQLite in UPGRADING, checked by the test suite
  on MySQL 8.4, MariaDB 11.4, PostgreSQL 16 and SQLite with default and prefixed names). Buckets per UTC day,
  channel, provider, credential and tenant; counters, status classes, retries, bytes, rows stored, duration sum / max
  and a histogram for the p95 upper bound. **Exactly once** under Messenger redelivery (row or ledger insert and the
  bucket upsert in one transaction: MySQL / MariaDB `ON DUPLICATE KEY UPDATE`, PostgreSQL and SQLite `ON CONFLICT`).
  Late events land on their own UTC day; events older than the cutoff are not counted. Unsampled successes and
  `per_call: false` providers (uptime probes) are count-only events; the web channel only with `rollup.web` (Q-O6).
- **Read API** `HttpLogUsageReaderInterface` (public alias): `daily()`, `iterateDaily()`, `calls()` (keyset pages of
  per-call usage rows) and `lastUsed()`, always with an explicit `forTenant()` / `platform()` scope.
- `nowo:http-log:rollup:backfill [--from] [--to] [--dry-run]`: buckets from per-call rows for days without buckets
  (never today, never a day that has buckets; sampled channels reported as lower bounds).
- **Retention and erasure** (S6): purge scopes for each named channel, `rollup` (`retention_months`, calendar months,
  13 keeps the same month last year) and `ledger` (`dedupe_days`); `HttpLogUsageEraserInterface` (public alias):
  `countByCredential()`, `eraseCredential()` (rows deleted, buckets folded into credential id `~erased`),
  `eraseTenant()`.
- `HttpLogContext::push()` / `restore()`.
- Measured (WSL Docker, `--cpus 1`): the 13-month daily query of one tenant on 400 000 buckets takes 8–22 ms on
  MySQL 8.4, MariaDB 11.4 and PostgreSQL 16; one bucket takes 170–310 events/s on MySQL 8.4 with the default
  `innodb_flush_log_at_trx_commit=1` (commit-bound: 690 with `=2`), 720–920 on PostgreSQL, 860 on MariaDB, one
  connection, sequential (`RollupPerformanceTest`, `HTTPLOG_TEST_PERF=1`).

### Changed

- With named channels or `rollup.web`, `HttpLogSubscriber` evaluates the web ignores before the web sampling (same
  outcome). Without them the 1.14 flow runs unchanged.
- Outbound and mailer captures carry `row` / `rollup` flags in their payload only when the rollup is on; their 1.15
  payload is unchanged otherwise.
- The admin purge form purges per-call rows only (`PurgeHttpLogService::purgeEntriesByRetention()`), never the rollup.
- With the rollup tables mapped, `doctrine.table_prefix` + `doctrine.table_name_prefix` may be at most 48 characters.
- `provider.per_call` / `rollup` options of `outbound` and `mailer.rollup` now take effect while the rollup is on.
- `ignore_path_prefixes` and the admin UI prefix are compared with the **decoded** path (`rawurldecode`), like the
  router and the firewall: `/%61dmin/http-log` is the admin UI, `/%61pi/x` is under an ignored `/api` (audit JA-M2).

### Security (audit JA, before the 1.16.0 tag)

- **JA-M1:** request credentials shorter than 8 characters (a redacted query param, header or cookie) are matched by
  exact equality only, so `?token=-` or `X-Api-Key: 1` can no longer blank the resolver context of the request (and
  move a token's use out of `lastUsed()` and the tenant's usage). Longer ones keep the contains / prefix rule.
- **JA-M2:** named channels match the decoded path, the same as the router and firewall. Before, `/%61pi/share/<token>`
  was served by the API but recorded as a web row with the raw path.
- **JA-L1:** an empty resolver result before the controller is not cached: a request the controller authenticates
  (`security: false` surfaces) is resolved again at terminate. A resolver may be called twice when it answers null.
- **JA-L2:** the ledger row is dated by the event's first processing, so `dedupe_days` runs from the first count
  (a backlogged event keeps its full window). With the rollup on, a counted channel's `retention_days` must be at
  least `rollup.dedupe_days` (checked at compile time): its row is the event's dedupe key.
- **JA-L3:** documented: one bucket serialises its writes; put public channels on `async: true` with a dedicated
  transport and rate-limit them until the batch handler lands.
- JA-I2: `--channel=rollup --days=N` never deletes a day of the current month. JA-I3: a decorated
  `security.untracked_token_storage` no longer breaks boot (early resolution is then skipped), and a lazy
  authenticator failing at terminate gives a null token instead of dropping the capture. JA-I4: an untrusted `Host`
  is stored as NULL and the request still recorded. JA-I8: `UsageSchemaTest` drops leftover rollup tables.

[1.16.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.16.0

## [1.15.0] - 2026-11-09

HL-1 usage capture, part 1 (contract slices S1 Foundation, S2 Outbound, S3 Mailer). Every new block is off by default:
a host that enables nothing gets no new listener, decorator or schema change, the 1.14 Doctrine mapping and the same
web payloads, rows, exports and admin pages (BC tests on a booted kernel).

### Added

- **Usage columns, mapped only when a feature is enabled** (`UsageColumnsListener`, Q-O1): `channel`, `provider`,
  `credential_kind`, `credential_id`, `tenant_key`, `project_key`, `response_bytes`, `retry_count`, `error_kind`,
  `messenger_message_id`, `external_message_id` (all nullable) and five `idx_http_log_*_created` indexes. DDL for
  MySQL / MariaDB, PostgreSQL and SQLite in [UPGRADING](UPGRADING.md#to-1150); a test applies it to a 1.14 table
  and checks the schema diff is empty (default and prefixed names).
- `project_key`: the project (e.g. a project UUID) of the call when the context knows it, so support can filter one
  project's calls; the tenant stays the organization.
- Usage rows are an **allowlist** (`UsageCapture`, `UsageFieldGuard`): only the listed columns exist in the capture and
  in its `PersistHttpLogMessage` payload; every value is validated against its charset and length and replaced by
  NULL (never cut) when it fails. Bodies, headers, query strings, user info and exception messages have no field.
- Usage rows are written with one DBAL `INSERT` (`UsageEntryWriter`), never `flush()`: a log write cannot flush the
  host's pending entities. Rows are deduplicated on a server-generated event id (32 hex).
- `HttpLogBuffer`: captures are written at `kernel.terminate`, `console.terminate` and after each worker message
  (handled or failed), or every 100 captures (cap 500, overflow counted in one warning); a host transaction rollback
  does not roll back the log of its failed call. Capture is suspended while the bundle dispatches or writes.
- `HttpLogContext` (public service): `run($tenantKey, $fn, $projectKey = null)` gives outbound calls and mails their
  tenant and project; frames are always popped, `kernel.reset` empties the stack.
- Messenger middleware `nowo_http_log.messenger.context_middleware` and `HttpLogContextStamp`: a stable message id
  and the tenant / project in scope travel with the envelope; the handler runs inside that frame
  (`messenger.middleware`, `messenger.propagate_tenant`).
- **Outbound HttpClient capture** (`outbound`, S2): `LoggingHttpClient` decorates `http_client` and the scoped
  clients selected by `outbound.clients` / `exclude_clients` at `decoration_priority` -1024 (outermost: one row per
  logical call after retries, `retry_count` from the response; tracing decorators unaffected). The response is the
  inner client's, unchanged and still lazy; the body is never read. Recorded exactly once at the last chunk, a fatal
  error, `cancel()` or destruct. Provider map (`clients`, `hosts` with one leading wildcard label, `path_templates`,
  `path_depth`, `per_call`), unmapped hosts as provider `other` (`record_unmapped`), error kinds (`timeout`, `dns`,
  `connect`, `tls`, `transport`, `redirect_limit`, `cancelled`, `http_4xx`, `http_5xx`, `exception`), errors always
  kept and successes sampled (`sampling_rate`). Relative URLs of scoped clients are resolved from the response.
- `OutboundUrlTemplater`: drops query string, fragment and user info; host lower-cased (IDN as punycode), port only
  when not the default; path as a template (versions and known path words kept, else `{uuid}`, `{id}`, `{seg}`, `{x}`, rest `/{…}`, at most 16
  segments / 512 characters; see IX-L1 above for the word rule).
- `HttpLogHttpClientWrapper::wrap($client, $provider, $clientId = null)` (public service) for clients built in code;
  inert while outbound capture is disabled, never wraps twice.
- Per-request option `extra.nowo_http_log` (`tenant_key`, `project_key`, `provider`, `skip`), removed before the
  inner client sees the request.
- **Mailer capture** (`mailer`, S3): `MailerCaptureListener` on `MessageEvent` (queued messages skipped),
  `SentMessageEvent` and `FailedMessageEvent`: one row per send attempt (failover: one per attempt), transport scheme
  and host without credentials, provider from `mailer.providers` (else the scheme), `external_message_id` from the
  sent Message-ID, `http_4xx` / `http_5xx` with the provider status for `HttpTransportException`. Pending starts live
  in a `WeakMap` keyed by the message (no growth when a send never completes).
- Per-channel retention: `outbound.retention_days`, `mailer.retention_days`; `nowo:http-log:purge --channel=…`
  (repeatable) with one output line per scope; `PurgeHttpLogService::purgeScopes()` / `scopes()` / `cutoff()`.
- Admin UI with a feature enabled: channel / provider columns, channel, provider, tenant, project and credential-kind
  filters, the usage fields on the entry page, usage columns appended to CSV / JSON exports.
- `HttpLogChannelAccessCheckerInterface` (optional, on the configured access checker): denied channels are left out
  of the list, the entry page (same 404 as a missing entry), the export and the purge.

### Security (audit IX, before the 1.15.0 tag)

- **IX-M1:** background exports honour `HttpLogChannelAccessCheckerInterface`. The store stays shared by every
  exporter (as in 1.14), but an export now remembers the channels it was built for (`<name>.channels`, 0600) and is
  listed and downloadable (same 404 by direct URL) only by users allowed every one of them; unrestricted exports are
  hidden from restricted users.
- **IX-L1:** the URL templater keeps a path segment verbatim only when it is a version (`v2`) or a known path word
  (built-in REST words plus the new `outbound.path_words`); any other word-like segment becomes `{seg}`, so short
  letter-only webhook keys and topic names never reach `path`.
- **IX-L2:** one `stream()` over responses of several decorated clients works like the bare clients (streamed per
  client) instead of throwing "Cannot stream AsyncResponse objects with many clients".
- **IX-L3:** the web channel's own `PersistHttpLogMessage` dispatch / write also suspends outbound capture; new
  `outbound.exclude_hosts` for transport, database or secret hosts.
- IX-I2: a call whose URL cannot be parsed still gets the provider of its client id. IX-I5: the channel filter lists
  only the channels the user may see. IX-I1 / IX-I3 documented (host stored as is; size-triggered flush with
  `async: false`).

### Changed

- With a usage feature enabled, `doctrine.table_prefix` may be at most 34 characters (longest usage index name: 29).
  Unchanged (39) otherwise.
- `nowo:http-log:purge` and `PurgeHttpLogMessage` without arguments purge each scope by its own retention once a usage
  feature is enabled; a bare `--days` still purges every row of every channel.

[1.15.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.15.0

## [1.14.1] - 2026-11-09

Target: **1.14.1** (patch; no schema change).

### Security

- **Log integrity:** the stored `request_id` (unique column, async retry dedupe key) is now always generated by the
  bundle (32 random hex characters per logged request). It was taken from the client's `X-Request-Id` header, so a
  client could send the same id on many requests and collapse them into a single log row, hiding its activity and
  skewing counts. The client's `X-Request-Id` is no longer used for the key or for dedupe; it stays in the captured
  request headers (`request_headers`) for correlation only. See [UPGRADING](UPGRADING.md#to-1141).

[1.14.1]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.14.1

## [1.14.0] - 2026-11-03

replaceable table-name prefix (doctrine.table_name_prefix), stricter prefix validation

### Added

- `doctrine.table_name_prefix` (default `nowo_http_log_`) replaces the `nowo_http_log_` part of the table name
  (`http_log_` gives `http_log_entry`), so a host can name the table after its own conventions. With the default the
  table, index and unique names are byte-identical (golden schema test). `doctrine.table_prefix` keeps prepending to
  the result. Rename SQL for MySQL / MariaDB and PostgreSQL in [UPGRADING](UPGRADING.md#to-1140).
- `nowo_http_log.doctrine.entry_table` container parameter: the physical table name.
- `Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataEraserInterface` (public service): count and batched delete of the
  entries of a user identifier or a client IP, for GDPR erasure without SQL on the bundle table. Matching is exact
  (byte for byte, also under case- or accent-insensitive MySQL collations); IP addresses are not normalised; empty
  values and values with a NUL byte are rejected.

### Changed

- `doctrine.table_prefix` / `doctrine.table_name_prefix` must match `/^[a-z][a-z0-9_]*\z/` (uppercase and a leading
  digit are now rejected) and may not make a generated name longer than 63 characters; checked at compile time;
  `%env()%` is rejected for both options (table names are part of the schema).

[1.14.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.14.0

## [1.13.2] - 2026-10-30

Admin action form types are FormKit types (REQ-TWIG-005 rule 11, no markup change). See [UPGRADING](UPGRADING.md#to-1132).

### Changed

- **FormKit action types (REQ-TWIG-005 rule 11):** `AbstractHttpLogActionType` (and so `HttpLogExportType`, `HttpLogPurgeType`, `HttpLogDeleteType`) is now a FormKit Type: `#[FormKitConfig('http_log')]` + `FormOptionsTrait`, like `HttpLogFilterType`. Field names, CSRF token ids (`http_log_export` / `http_log_purge` / `http_log_delete`) and the rendered hidden fields are unchanged. The `http_log` profile is documented in [CONFIGURATION](CONFIGURATION.md#form-profile-formkit).

[1.13.2]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.13.2

## [1.13.1] - 2026-10-30

Bundle routes are opt-in again: controllers are no longer autoconfigured, so a host's routing.controllers import does not expose them (REQ-SF-004 rule 7). See [UPGRADING](UPGRADING.md#to-1131).

### Fixed

- **Admin routes registered without the route import:** on Symfony >= 7.3 the `#[Route]` attributes on the
  autoconfigured `HttpLogAdminController` gave it the `routing.controller` tag, so the host's default
  `controllers: { resource: routing.controllers }` registered the `nowo_http_log_admin_*` routes even when the host
  never imported `@NowoHttpLogBundle/Resources/config/routes.yaml` (access was still checked by the controller). The
  controller is no longer autoconfigured nor matched by the bundle's service glob; its definition carries the explicit
  `controller.service_arguments` and `container.service_subscriber` tags. The routes exist only through the
  documented import (REQ-SF-004 rule 7). Regression test with a kernel that only loads `routing.controllers`.

[1.13.1]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.13.1

## [1.13.0] - 2026-10-28

Performance: the admin list counts and paginates at most 10 000 entries; exports skip the per-batch COUNT. See [UPGRADING](UPGRADING.md#to-1130).

### Performance

- **Bounded admin list.** Every list page ran a full `COUNT` and an unbounded `OFFSET` over the whole log. The list now
  counts and paginates at most 10 000 matching entries (`HttpLogEntryRepository::MAX_LISTED`, header shows
  "10000+ entries"; narrow the filters to reach older ones) and clamps out-of-range pages. Background / CLI exports
  are unchanged but no longer run a `COUNT` for every batch.

[1.13.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.13.0

## [1.12.0] - 2026-10-28

Security: background exports are kept in a private, expiring store and downloaded from the admin list. See [UPGRADING](UPGRADING.md#to-1120).

### Security

- **Background exports no longer leak to the shared temp directory.** Exports above `export.max_sync_rows` were
  written by the Messenger handler to `sys_get_temp_dir()/http-log-export-<uniqid>.csv|json` with the default umask
  (usually world-readable), never deleted and never offered to the admin. They contain request logs (paths,
  headers, bodies, user identifiers). They now go through `ExportFileStore`:
  - private directory `export.directory` (default `%kernel.project_dir%/var/http-log/exports`, created `0700`),
    files `0600` with random names, written as `<name>.part` and published when complete;
  - downloadable from the admin list header (`nowo_http_log_admin_export_download`, users allowed to export only,
    `Cache-Control: private, no-store`);
  - deleted after `export.ttl_hours` (default 24) by the export job and when the admin list is opened.
- `ExportHttpLogService::exportToFile()` (also used by `nowo:http-log:export`) creates files `0600`.

### Added

- `export.directory` and `export.ttl_hours` options; `ExportHttpLogMessage::$exportName` (named exports in the
  private store; the handler ignores `outputPath` for them).

[1.12.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.12.0

## [1.11.0] - 2026-10-28

Security: the admin UI refuses to compile without form CSRF protection (CsrfProtectionPass, fail closed). See [UPGRADING](UPGRADING.md#to-1110).

### Security

- CSRF fails closed: new `CsrfProtectionPass` makes the container refuse to compile when the admin UI (`web_ui.enabled: true`) is on and form CSRF protection is unavailable (`security.csrf.token_manager` or `form.type_extension.csrf` missing, or `framework.form.csrf_protection: false`). Before, the purge / delete / export actions (`AbstractHttpLogActionType` declares its own CSRF options, checked by `validateActionForm()`) accepted tokenless cross-site POSTs in that configuration. See [UPGRADING](UPGRADING.md#unreleased) and [SECURITY](SECURITY.md#threat-model).

[1.11.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.11.0

## [1.10.0] - 2026-10-21

Wave-2 REQ compliance: configurable table prefix, injected URL generator, raw-form gate in release-check. See [UPGRADING](UPGRADING.md#to-1100).

### Added

- `doctrine.table_prefix` config and `Doctrine\TablePrefixListener`: prefixes `nowo_http_log_entry` and its index names, never host entities (REQ-ORM-002).
- `make check-no-raw-html-form` in `release-check` (REQ-MAKE-002, REQ-TWIG-005).

### Changed

- `HttpLogAdminController` receives the URL generator by constructor injection (autowired router) instead of `$this->container->get('router')`; commands are registered by `#[AsCommand]` + autoconfiguration without manual `console.command` tags (REQ-SF-003).
- Rector uses the PHP 8.2 sets (package floor) with at most 2 workers (REQ-CS-003); demo `.gitignore` ignores `node_modules` / `.pnpm-store` (REQ-GITIGNORE-003).
- `.scripts/check-no-cursor-coauthor.sh` accepts git worktrees (REQ-GIT-001).
- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).
- Documentation: wave-1 REQ compliance — README documentation list order and labels, CONTRIBUTING Cursor Agent settings and post-release-commit trailer check, canonical bug-report template, tailored Spec Kit constitution, doc TOCs, Spec Kit inventory refresh (50/50, UiKit page contract).

[1.10.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.10.0

## [1.9.1] - 2026-10-18

Requires UiKit ^2.2 (table partial, scripts partial in the demo); filter placeholders and literal choices translated once. See [UPGRADING](UPGRADING.md#to-191).

### Changed

- Requires `nowo-tech/ui-kit-bundle` **^2.2** (default-theme polish: table header hierarchy, striped rows, subtle
  badges, grid filter row, solid destructive confirm button with a close button). See [UPGRADING](UPGRADING.md#unreleased).
- Entry page: the header / query-parameter tables render through the UiKit 2.2 `table` partial (`View\Table` from
  `AdminPages::keyValueTable()`, also exposed as `payload.tables`) instead of a hand-written table; the section title
  is the table's accessible name.
- Demo: the host layout prints the UiKit runtimes through the UiKit `scripts` partial (the hand-kept list lacked
  `dialog` and `theme`).

### Fixed

- List: entries logged within the same second are ordered newest id first (stable pages; the order was undefined).
- **Missing / double translations (profiler):** the filter's HTTP method and body-type choices are literals
  (`choice_translation_domain: false`), no longer looked up in `NowoHttpLogBundle`. The filter placeholders moved from
  the page model (`FilterField`, translated there and again by the form theme; `404`, `/api/`, `app_` were looked up
  as messages) to the `HttpLogFilterType` field `attr` as catalogue keys translated once (`filter.q_placeholder`, new
  `filter.status_code_placeholder`, `filter.path_placeholder`, `filter.route_name_placeholder`, 7 locales). A contract
  test renders every admin page with a `DataCollectorTranslator` and asserts no missing message.

[1.9.1]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.9.1

## [1.9.0] - 2026-10-18

`/_wdt/` and `/_profiler/` ignored by default, entry URL keeps the port; readable entry page (badges, header tables, pretty JSON bodies) and grouped list filters. See [UPGRADING](UPGRADING.md#to-190).

UI polish after the UiKit page-contract migration: readable entry page, grouped filters, WebProfiler requests no longer recorded by default. See [UPGRADING](UPGRADING.md#to-190).

### Changed

- **Default ignored paths:** `/_wdt/` and `/_profiler/` (Symfony WebProfiler toolbar and profiler) join the kit defaults merged into `ignore_path_prefixes` while `ignore_default_path_prefixes` is `true` (default), so dev traffic of the toolbar no longer floods the log. Set `ignore_default_path_prefixes: false` to record them again (then list the kit paths you still want skipped). The demo drops its explicit `ignore_path_prefixes`.
- **Entry page:** Method and Status as badges (the list's `badge_neutral` / status slots), request ID, URL and route in the UiKit `code` slot; request / response headers and query parameters as name | value tables (list values joined, redacted values stay `[REDACTED]`, muted); JSON bodies pretty-printed; bodies in a bounded, scrollable code block that wraps long lines (UiKit `nowo-ui-code-block--wrap`) and ends on a whole line. New stylesheet `bundles/nowohttplog/css/nowo-http-log-admin.css` (entry page only; run `assets:install`). Template variable `payload` (`AdminPages::payload()`); without it the 1.8 JSON blocks render.
- **Filters:** fields ordered search, method, status, client IP / path, route, body type / created from–to, sized for the UiKit filter row; method and body type selects start with "Any"; placeholders for search, status, path and route; created from / to are native date-time pickers (`datetime-local`, minute precision). New translation keys `filter.any`, `filter.q_placeholder`, `admin.field.name`, `admin.field.value` (all 7 locales).
- **List:** paths use the UiKit `code` slot instead of a bare `<code>`.

### Fixed

- **Entry URL lost the port:** the recorder stored `Request::getHost()`; it now stores `getHttpHost()`, so non-default ports are kept (`http://127.0.0.1:8000/api/echo`). Entries recorded before keep their port-less host.

[1.9.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.9.0

## [1.8.0] - 2026-10-18

Admin pages on the UiKit 2.1 standard page contract (REQ-UI-003): list and show scaffolds, one host theme, deprecated chrome keys bridged into `nowo_ui_kit.theme.bundles`. See [UPGRADING](UPGRADING.md#to-180).

### Added

- `View\AdminPages`: builds the UiKit view models of the admin pages (`ListPage`, `ShowPage`).
- `DependencyInjection\LegacyThemeBridge`: prepends the deprecated 1.x chrome keys into `nowo_ui_kit.theme.bundles.NowoHttpLogBundle`.
- `docs/TEMPLATES.md` (pages → scaffolds, data blocks) and the page contract test (`tests/Integration/PageContractTest.php`, UiKit `PageContractTestCase`).
- Status badges per status class, breadcrumbs on the entry page, responsive (card) list on narrow screens, clear-filters link.
- Demo: UiKit admin layout bridge, admin smoke test, WebP screenshots in `docs/images/demo/`.

### Changed

- Requires `nowo-tech/ui-kit-bundle` **^2.1** (CSRF-safe `FormView` header actions, `code_block` slot).
- `admin/index.html.twig` extends `@NowoUiKit/page/list.html.twig` (header actions, filter row, table, row actions, confirm dialog, pagination, empty state from UiKit); `admin/show.html.twig` extends `@NowoUiKit/page/show.html.twig` (summary section, payload cards in `nowo_ui_show_after`).
- `web_ui.layout_template` defaults to `null` (UiKit admin layout, `nowo_ui_kit.layouts.admin`); the 1.x value `@NowoHttpLogBundle/layout.html.twig` is read as `null`.
- Purge and delete confirm in the UiKit `<dialog>` (no inline `confirm()`); export / purge / delete are `FormView`-backed UiKit POST actions (same forms, flat `_token`, same CSRF ids; export posts `format` and the criteria as hidden fields, as in 1.x).
- Export / purge / delete actions are hidden from users the access checker denies.
- Payload `<pre>` blocks of the entry page use the UiKit `code_block` theme slot (`nowo-ui-code-block`; the class `nowo-http-log-code` stays on the element); the deprecated `web_ui.css_classes.code` is bridged to `code_block`.
- The `http_log` FormKit profile no longer adds `mb-2` row classes (filter rows sit in the UiKit filter row).
- Removed: `layout.html.twig` (Bootstrap CDN), `admin/base.html.twig`, `admin/_pagination.html.twig`, `admin/_macros.html.twig`, the Twig globals `nowo_http_log_layout_template` / `nowo_http_log_css_framework`, the 1.x page blocks other than `nowo_http_log_section_*` / `nowo_http_log_detail_sections` (see UPGRADING "Template contract").

### Deprecated

- `web_ui.css_framework`, `web_ui.icon_set` (use `nowo_ui_kit`; still seeded when set here), `web_ui.templates.filter` / `templates.pagination`, `web_ui.css_classes`, `web_ui.attributes` (bridged into `nowo_ui_kit.theme.bundles.NowoHttpLogBundle`), `web_ui.ui_kit_stylesheet`, `web_ui.header_actions_in_layout`, `web_ui.confirm_attributes`, `web_ui.ui_kit_row_actions` (no effect). Removed in 2.0.
- `admin/_filter.html.twig` (BC partial for `web_ui.templates.filter` with the `nowo_http_log_filter_*` blocks), `View\AdminChrome` and the `nowo_http_log.web_ui.chrome` parameter.

[1.8.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.8.0

## [1.7.0] - 2026-10-14

Kit ignore paths and keyset purge (spec [003](../specs/003-kit-ignore-paths-keyset-purge/spec.md)).

### Added

- `ignore_default_path_prefixes` (default `true`): the kit-internal paths `/_nowo/` (HotReloadBundle client / shared worker), `/sw.js` and `/manifest.webmanifest` (PwaBundle) are skipped by default, merged with `ignore_path_prefixes` (host entries first, duplicates removed). Set it to `false` to record them.
- `retention.purge_batch_size` (default `1000`, min `1`): rows deleted per statement by the purge.

### Changed

- `HttpLogEntryRepository::deleteOlderThan()` deletes in keyset batches (`id > :afterId ORDER BY id LIMIT n`, then `DELETE … WHERE id IN (…)`, until a short batch) instead of one unbounded `DELETE`, so purging a large log no longer locks the table or writes one huge binlog event. New optional argument `int $batchSize = 1000`; the return value (rows deleted) and the purge command / message output are unchanged.
- The criteria purge (admin UI with filters) uses `retention.purge_batch_size` for its id batches (was a fixed 500).

[1.7.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.7.0

## [1.6.0] - 2026-10-13

### Fixed

- Admin filter form no longer renders raw FormKit convention keys: the seeded FormKit profile `http_log` now sets `convention_requires_translation: true`, so `http_log_filter.<field>.help` / `.placeholder` are only applied when the host translates them (previously every filter field showed e.g. `http_log_filter.method.help` under the input and as its placeholder). With FormKit 2.9+ the optional `method` / body-type selects also no longer carry a meaningless `placeholder` attribute; they keep their empty "any" option.

### Changed

- Requires `nowo-tech/form-kit-bundle` **^2.10** (was ^2.0) for `convention_requires_translation` and the 2.9 ChoiceType placeholder semantics.
- Tests: admin golden files pin the bundle chrome only (filter rows rendered through a label-only stub theme); the real filter widgets are asserted as invariants, so FormKit / Symfony Form markup changes no longer break the goldens.

[1.6.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.6.0

## [1.5.1] - 2026-10-13

### Fixed

- The recorder now always ignores the admin UI path (`web_ui.path_prefix`, when `web_ui.enabled` is true) in addition to `ignore_path_prefixes`, so a custom prefix no longer makes the admin UI record its own requests (e.g. when `ignore_routes` was overridden without `nowo_http_log_*`). The match is segment-aware: `/ops/logs` skips `/ops/logs` and `/ops/logs/42`, not `/ops/logsearch`. Docs (CONFIGURATION, UPGRADING) updated accordingly.

[1.5.1]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.5.1

## [1.5.0] - 2026-10-13

### Security

- `HttpLogEntryRepository::findOneBy()` no longer interpolates criteria / order-by keys into DQL: field names are checked against the entity metadata and unknown ones throw `InvalidArgumentException` (a caller-controlled key such as `id = 1 OR 1 = 1 OR e.id` could rewrite the query). `null` criteria now use `IS NULL` and list values `IN (...)`, like Doctrine's own repository.

### Fixed

- `web_ui.path_prefix` is now the prefix of the admin routes (it was only exposed to Twig; routes were hard-coded to `/admin/http-log`). A trailing `/` is trimmed; `/` alone and values containing `%` are rejected.
- `export.formats` is enforced: only enabled formats get an export button, the export action answers 400 for a disabled format and `nowo:http-log:export` exits with `INVALID`. Values are restricted to `csv` / `json` and at least one is required.
- `web_ui.icon_set` is now a real configuration key (default `bootstrap-icons`); it was read when seeding NowoUiKitBundle but could not be set.

### Changed

- Requirements: Symfony `^7.4 || ^8.0` (was `^7.0`); CI no longer runs a 7.0 cell (it resolved to 7.4 anyway).

[1.5.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.5.0

## [1.4.0] - 2026-10-13

### Security

- Request/response bodies are now redacted **before** `max_body_bytes` truncation; previously a truncated JSON body failed to decode and was stored raw with its secrets. A JSON-looking body that cannot be parsed is stored as `[REDACTED-UNPARSEABLE]`.
- `application/x-www-form-urlencoded` bodies and `multipart/form-data` text fields are now redacted by field name (`query_params` + `json_paths` leaf keys); they were stored verbatim (e.g. Symfony form-login `_password`).
- Default redaction keys now include `_password`, `_token`, and `_csrf_token` (query params / form fields and `*.` JSON paths).
- **Behaviour change:** `security.access_roles: []` now denies everyone (fail closed) instead of granting access to all users including anonymous ones. Use `allow_unauthenticated: true` or a custom `access_checker` to open the admin UI deliberately.

### Fixed

- Admin purge rejects a negative or non-integer `older_than_days` with HTTP 400 (a negative value produced a future cutoff via `--N days`); `PurgeHttpLogService::purgeByRetention()` rejects negative overrides.

[1.4.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.4.0

## [1.3.1] - 2026-10-13

### Changed

- Repository: `.twig-cs-fixer.cache` is now git-ignored.

[1.3.1]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.3.1

## [1.3.0] - 2026-10-13

Admin chrome hooks (spec [002](../specs/002-admin-chrome-hooks/spec.md)): hosts theme the admin pages from config, blocks and partial paths instead of forking them. Default output is unchanged (whitespace-equivalent to 1.2.0, golden-file tests) except the pagination links, which now keep the filters (see Fixed).

### Added

- **Twig blocks** around every chrome region of `admin/index.html.twig` (page header / intro, filter, toolbar, summary, header actions and each export / purge action, table, table head, table row, row actions, empty state, pagination), `admin/show.html.twig` (page header / intro, header actions, details, each detail section, delete form) and `admin/_filter.html.twig` (panel, fields, actions).
- **`web_ui.templates`** (`index`, `show`, `filter`, `pagination`): page and partial paths. The pagination partial (new `admin/_pagination.html.twig`) receives a UiKit `_pagination` compatible contract (`pagination`, `item_count`, `route`, `query`, …).
- **`web_ui.css_classes`**: strict CSS class map, defaults equal to the shipped classes; `null` keeps the UiKit macro for `css_framework` (`ui.btn`, `ui.row_actions`), `''` drops the attribute; optional `results` / `show` wrappers.
- **`web_ui.attributes`**: extra HTML attributes (e.g. `data-testid`) on filter, results, toolbar, table, detail and delete-form elements.
- **`web_ui.ui_kit_stylesheet`** (default `true`), **`web_ui.header_actions_in_layout`** (default `false`; the bundle layout renders `block('nowo_http_log_header_actions')` in its header), **`web_ui.confirm_attributes`** (CSP-friendly replacement for the inline `onsubmit` confirm; `{message}` placeholder), **`web_ui.ui_kit_row_actions`** (UiKit `_row_actions` partial).
- `@!NowoHttpLogBundle` Twig namespace (bundle originals) so `templates/bundles/NowoHttpLogBundle/` overrides can extend the page they replace and override only blocks.
- `Nowo\HttpLogBundle\View\AdminChrome` (defaults + `fromWebUi()`); the controller passes the resolved chrome to Twig as `nowo_http_log_ui`.
- Translation keys `flash.export_queued`, `flash.purged`, `flash.deleted` in all seven catalogues.
- Docs: USAGE "Customising the admin chrome" (blocks, thin forks, header actions bridge, pagination contract, Symfony Beacon per-fork verdict and config); CONFIGURATION "Admin chrome hooks (1.3.0)".

### Changed

- Admin flash messages are translated through the `NowoHttpLogBundle` catalogue (English text unchanged; English fallback when no translator is available).
- German, French, Italian, Dutch and Portuguese catalogues: the admin strings are now translated (they still held English placeholders).
- The container parameter `nowo_http_log.web_ui` escapes `%` in string values (so class names such as `w-[50%]` survive parameter resolution).
- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.

### Fixed

- **Pagination kept dropping the filters:** list pagination links carried the criteria as flat query keys (`?method=GET&page=2`, dates lost entirely) while the GET filter form reads `http_log_filter[...]`, so page 2 was unfiltered. Links now carry the active criteria in the form's shape (`?http_log_filter[method]=GET&http_log_filter[createdFrom]=2026-08-01T09:30&page=2`); the same `query` is passed to custom pagination partials.

### Tests

- `tests/Integration/PaginationKeepsFiltersTest.php`: real controller, filter form and templates — filtered list, follow the "next" link, page 2 is still filtered.
- `tests/Integration/AdminChromeRenderTest.php` renders the real templates (forms, routing, translations, UiKit macros) against 1.2.0 golden files and covers every hook; configuration, extension, compiler-pass and controller tests for the new options. Coverage stays at 100 % lines.

[1.3.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.3.0

## [1.2.0] - 2026-10-09

### Added

- `ignore_unrouted` (default `false`): skip requests no route matched (scanner 404s such as `/wp-login.php`, `/.env`), which `ignore_routes` cannot express.

### Changed

- **Doctrine ORM SortDirection:** replace string `'ASC'`/`'DESC'` in `#[ORM\OrderBy]` and QueryBuilder `orderBy`/`addOrderBy` with `SortDirection::Ascending`/`Descending` (doctrine/orm deprecation, https://github.com/doctrine/orm/issues/11313); require `doctrine/orm` `^3.7` where applicable.

### Fixed

- `HttpLogEntryRepository::findOneBy()` maps the string `$orderBy` direction straight to `SortDirection` (removes an always-false `instanceof`, PHPStan 2.3); integration test covers the ascending fallback.

### Dependencies

- `doctrine/orm` runtime constraint is now `^3.7` (was `^2.15 || ^3.0`).
- Dependabot: `doctrine/orm` 3.7.3, `igor-php/igor-php` `^0.10.0` (dev), `phpstan/phpstan-phpunit`.
- Composer refresh: `nowo-tech/form-kit-bundle` 2.6.1, `nowo-tech/ui-kit-bundle` 1.9.1; dev `phpstan/phpstan` 2.3.1, `phpstan/phpstan-symfony` 2.1.0, `rector/rector` 2.7.0.
- Demo: Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `twig/twig` 3.30.0; regenerated `config/reference.php`.

[1.2.0]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.2.0

## [1.1.7] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.1.7]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.1.7

## [1.1.6] - 2026-09-24

### Fixed

- **FrankenPHP worker mode (no kernel reset):** `HttpLogRecorder` detaches every persisted `HttpLogEntry` after flush, so the identity map no longer grows by one entry per logged request.
- **FrankenPHP worker mode:** the recorder resolves its EntityManager through `ManagerRegistry` and resets it when it is closed (before persisting, and after a failed flush), so one failed log insert no longer leaves the EntityManager closed for the rest of the worker's life.
- **FrankenPHP worker mode:** the user identifier is only recorded when a firewall with security enabled handled the current request, so a token left in `TokenStorage` by a previous request is no longer attributed to an anonymous request.
- **FrankenPHP worker mode:** `HttpLogEntryRepository` resolves the EntityManager from `ManagerRegistry` on every call (and resets a closed manager), so DoctrineBundle's cached repository EM is not reused after `resetManager()`.
- **Export / purge / admin:** export batches and admin list/detail detach after use; `purgeByCriteria()` selects ids only (`findIdsFiltered()`); persist handler detaches idempotency lookups.
- Fixed PHPStan findings (0 errors at level 8).

### Added

- `HttpLogEntryRepository::findIdsFiltered()` and `HttpLogEntryRepository::detachAll()`.
- Optional `HttpLogRecorder` constructor arguments `$managerRegistry` and `$security` (autowired; existing arguments unchanged).
- Worker-mode regression tests (`HttpLogRecorderWorkerModeTest`) and [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
- Specs: `FR-WORKER-001`…`005` / `SC-05` in `specs/001-baseline/spec.md`.

### Documentation

- [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md), [USAGE.md](USAGE.md), [UPGRADING.md](UPGRADING.md), README — worker mode without kernel reset.

## [1.1.5] - 2026-08-24

### Changed

- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.1.5]: https://github.com/nowo-tech/HttpLogBundle/releases/tag/v1.1.5

## [1.1.4] - 2026-08-20

### Security

- **Flex recipe:** ship `when@prod` PII hardening (sampling 0.25, response bodies off, retention **14** days) and `security_nowo_http_log.yaml` access_control. Prefer **`^1.1.4`**.

## [1.1.3] - 2026-08-19

### Changed

- **Maintainer:** refresh `composer.lock` after PHP CS Fixer CI run.

## [1.1.2] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

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
