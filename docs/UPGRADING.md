# Upgrading

This document describes how to upgrade between versions of **Http Log Bundle**.

## Table of contents

- [Unreleased](#unreleased)
- [To 1.19.0](#to-1190)
  - [Framework-free request-context resolver (deprecation)](#framework-free-request-context-resolver-deprecation)
- [To 1.18.0](#to-1180)
- [To 1.17.0](#to-1170)
  - [Index migration](#index-migration)
  - [Prefix filters (opt-in)](#prefix-filters-opt-in)
  - [Template overrides of the list](#template-overrides-of-the-list)
- [To 1.16.3](#to-1163)
- [To 1.16.2](#to-1162)
- [To 1.16.1](#to-1161)
- [To 1.16.0](#to-1160)
  - [Named inbound channels (opt-in)](#named-inbound-channels-opt-in)
  - [Daily rollup (opt-in)](#daily-rollup-opt-in)
  - [Retention and erasure](#retention-and-erasure)
  - [New optional APIs](#new-optional-apis)
- [To 1.15.0](#to-1150)
  - [Outbound and mailer capture (opt-in)](#outbound-and-mailer-capture-opt-in)
  - [Behaviour once a block is enabled](#behaviour-once-a-block-is-enabled)
  - [New optional APIs (available with every block disabled)](#new-optional-apis-available-with-every-block-disabled)
- [To 1.14.1](#to-1141)
- [To 1.14.0](#to-1140)
- [To 1.13.2](#to-1132)
- [To 1.13.1](#to-1131)
- [To 1.13.0](#to-1130)
- [To 1.12.0](#to-1120)
  - [Background exports in a private directory](#background-exports-in-a-private-directory)
- [To 1.11.0](#to-1110)
  - [Admin UI requires form CSRF protection at compile time](#admin-ui-requires-form-csrf-protection-at-compile-time)
- [To 1.10.0](#to-1100)
- [To 1.9.1](#to-191)
- [To 1.9.0](#to-190)
- [To 1.8.0](#to-180)
  - [Template contract](#template-contract)
  - [Deprecated configuration](#deprecated-configuration)
- [To 1.7.0](#to-170)
- [To 1.6.0](#to-160)
- [To 1.5.1](#to-151)
- [To 1.5.0](#to-150)
- [To 1.4.0](#to-140)
- [To 1.3.1](#to-131)
- [To 1.3.0](#to-130)
- [To 1.2.0](#to-120)
- [To 1.1.7](#to-117)
- [1.1.6](#116)
- [From 1.1.4 to 1.1.5](#from-114-to-115)
- [1.1.4 (Flex when@prod PII + access_control)](#114-flex-whenprod-pii--access_control)
- [1.1.3 (maintainer lock refresh)](#113-maintainer-lock-refresh)
- [1.1.2 (composer audit CI)](#112-composer-audit-ci)
- [1.1.1 (Symfony 8 demos / Hot Reload 1.4)](#111-symfony-8-demos--hot-reload-14)
- [1.1.0](#110)
  - [UiKitBundle (REQ-UI-001-kit)](#uikitbundle-req-ui-001-kit)
  - [FormKitBundle (admin forms)](#formkitbundle-admin-forms)
  - [Twig Extra Bundle (REQ-TWIG-004)](#twig-extra-bundle-req-twig-004)
  - [Twig-CS-Fixer (maintainers)](#twig-cs-fixer-maintainers)
- [1.0.2](#102)
  - [Behaviour](#behaviour)
  - [Action required only if you customized Twig](#action-required-only-if-you-customized-twig)
- [1.0.1](#101)
- [1.0.0](#100)
  - [Install](#install)
  - [From this version onward](#from-this-version-onward)

## Unreleased

## To 1.19.0

From **1.18.0**:

```bash
composer update nowo-tech/http-log-bundle
```

Target **1.19.0**. No action is required: every 1.18 configuration and custom service keeps working, and the stored
rows are unchanged.

### Framework-free request-context resolver (deprecation)

`HttpLogRequestContextResolverInterface::resolve(Request, ?TokenInterface, string)` takes Symfony types, which
REQ-ARCH-001 rule 3 no longer allows in public ports. It is **deprecated in 1.19 and removed in 2.0**:

| Deprecated | Use instead |
|---|---|
| `Inbound\HttpLogRequestContextResolverInterface::resolve(Request, ?TokenInterface, string)` | `Inbound\HttpRequestContextResolverInterface::resolveContext(HttpRequestContext, string)` |
| `HttpLogRequestContextResolverInterface::TAG` | `HttpLogRequestContextResolution::RESOLVER_TAG` (same value, `nowo_http_log.request_context_resolver`) |

How to migrate a resolver:

1. Implement `Nowo\HttpLogBundle\Inbound\HttpRequestContextResolverInterface` (you may keep the old interface during
   the transition; the bundle calls the new method when both are implemented). Autoconfiguration tags it as before.
2. Read the request from the value object: `$context->routeName`, `$context->routeParam('id')`,
   `$context->attribute('…')`, `$context->header('…')`, `$context->userIdentifier`, `$context->roles`.
3. Headers are an allowlist: if your resolver reads a header (a tenant header), add it to
   `nowo_http_log.redaction.context_headers` (default: `accept`, `accept-language`, `content-type`, `user-agent`,
   `x-request-id`). Credential-like headers (`Authorization`, `Proxy-Authorization`, `Cookie`, `Referer`, `Forwarded`,
   `X-Original-URL`, `X-Rewrite-URL`, any name with token, secret, key, auth, session, signature, passw or cookie) are
   never given, even when listed.
4. Replace `$token->getAttribute('api_token')` (an object) with a **scalar id** your authenticator stores under a plain
   name, for example `$token->setAttribute('credential_id', (string) $apiToken->getId())`, read as
   `$context->tokenAttribute('credential_id')`. Attribute names with token, secret, key, auth, session, signature, passw
   or cookie are dropped, and values that reach the context must be ids, never secrets.
5. The credential itself is not in the context (no `Authorization`, cookies, credential or redacted headers, query
   string): a resolver must not derive anything from it, which the guard already required.

Other additions, all optional: `HttpLogContextInterface` (type-hint it instead of `HttpLogContext`) and the
`HttpLogEntryRecordedEvent` ([USAGE](USAGE.md#entry-recorded-event)). The event is not dispatched when `async: true`
writes through a bus with `doctrine_transaction`, and ids a listener copies must be erased together with the
bundle's erasure ports.

In 2.0 the deprecated interface and its `TAG` constant are removed.

## To 1.18.0

From **1.17.0**:

```bash
composer update nowo-tech/http-log-bundle
```

Nothing to change. New optional APIs: the GDPR erasure and export ports
(`HttpLogPersonalDataErasureInterface`, `HttpLogPersonalDataExporterInterface`, see
[USAGE](USAGE.md#gdpr-erasure-and-export)); `HttpLogPersonalDataEraserInterface` is unchanged.

The UserKit bridge is opt-in (`personal_data.enabled` defaults to `false`): nothing changes on upgrade. Set
`personal_data.enabled: true` to let UserKit erase / export HTTP logs (the `http_log` eraser and the `http_requests`
export section register then); keep `false` if you must retain access logs.

```yaml
nowo_http_log:
    personal_data:
        enabled: true
```

Before enabling it, remove your own UserKit export provider with the section key `http_requests`, if any: UserKit's
exporter throws a `LogicException` ("Duplicate personal data export section") on two providers with the same key, and
the whole export fails. Your own eraser for this table can go too (the bundle's `http_log` eraser replaces it; two
erasers would both run and the second would find nothing).

Behaviour change: captures of a user identifier or client IP erased earlier in the same request, command or message are
no longer written (the erasing request itself at `kernel.terminate`, buffered outbound / mailer captures).

## To 1.17.0

```bash
composer update nowo-tech/http-log-bundle
```

The admin list now reads only the columns it shows and pages by keyset (PERF-1147-02). Existing admin URLs and filters
keep working: a 1.16 `?page=n` link without `cursor` opens page `n` (read by offset, within the 10 000-entry list cap);
the links the list renders now carry `?cursor=` as well. Nothing changes in capture, export, purge or access checks.

### Index migration

Four existing indexes keep their names and gain a column, so a filtered page is read in `(created_at, id)` order from
one index. Everything works before you migrate (only slower on large logs). Generate the migration as usual
(`php bin/console doctrine:migrations:diff`), or apply the equivalent SQL (default names; add your
`doctrine.table_prefix` / `table_name_prefix`). On PostgreSQL and SQLite prefer the SQL below: `migrations:diff`
generates neither `CONCURRENTLY` nor `varchar_pattern_ops` on PostgreSQL (prefix filters then scan under a non-C
collation), and on SQLite it rebuilds the whole table to change an index:

```sql
-- MySQL / MariaDB
ALTER TABLE nowo_http_log_entry
    DROP INDEX idx_http_log_created_at,  ADD INDEX idx_http_log_created_at (created_at, id),
    DROP INDEX idx_http_log_route_name,  ADD INDEX idx_http_log_route_name (route_name, created_at),
    DROP INDEX idx_http_log_status_code, ADD INDEX idx_http_log_status_code (status_code, created_at),
    DROP INDEX idx_http_log_client_ip,   ADD INDEX idx_http_log_client_ip (client_ip, created_at);

-- PostgreSQL (CONCURRENTLY: outside a transaction, no write lock on a large table)
DROP INDEX CONCURRENTLY idx_http_log_created_at;  CREATE INDEX CONCURRENTLY idx_http_log_created_at ON nowo_http_log_entry (created_at, id);
DROP INDEX CONCURRENTLY idx_http_log_route_name;  CREATE INDEX CONCURRENTLY idx_http_log_route_name ON nowo_http_log_entry (route_name, created_at);
DROP INDEX CONCURRENTLY idx_http_log_status_code; CREATE INDEX CONCURRENTLY idx_http_log_status_code ON nowo_http_log_entry (status_code, created_at);
DROP INDEX CONCURRENTLY idx_http_log_client_ip;   CREATE INDEX CONCURRENTLY idx_http_log_client_ip ON nowo_http_log_entry (client_ip, created_at);

-- SQLite
DROP INDEX idx_http_log_created_at;  CREATE INDEX idx_http_log_created_at ON nowo_http_log_entry (created_at, id);
DROP INDEX idx_http_log_route_name;  CREATE INDEX idx_http_log_route_name ON nowo_http_log_entry (route_name, created_at);
DROP INDEX idx_http_log_status_code; CREATE INDEX idx_http_log_status_code ON nowo_http_log_entry (status_code, created_at);
DROP INDEX idx_http_log_client_ip;   CREATE INDEX idx_http_log_client_ip ON nowo_http_log_entry (client_ip, created_at);
```

Text filters now match `%` and `_` literally (in 1.16 they were LIKE wildcards).

### Prefix filters (opt-in)

By default (`web_ui.filter_match: contains`) the `q`, `path` and `routeName` filters keep matching anywhere in the value
(`LIKE '%value%'`, a scan of the table). With `prefix` they match the **start** of the value through indexes; the path
is matched on a new nullable column `path_prefix` (its first 191 characters, written with each entry; a longer needle
also checks the full path):

```yaml
nowo_http_log:
    web_ui:
        filter_match: prefix
```

Add the column and its index **before** deploying the configuration (the mapping is only added in `prefix` mode, so
`doctrine:migrations:diff` generates it once the option is set), then fill it for existing rows:

```sql
-- MySQL / MariaDB
ALTER TABLE nowo_http_log_entry ADD path_prefix VARCHAR(191) DEFAULT NULL,
    ADD INDEX idx_http_log_path_prefix (path_prefix, created_at);
UPDATE nowo_http_log_entry SET path_prefix = LEFT(path, 191) WHERE path_prefix IS NULL;

-- PostgreSQL: varchar_pattern_ops lets LIKE 'x%' use the index under a non-C collation (Doctrine sees no diff)
ALTER TABLE nowo_http_log_entry ADD path_prefix VARCHAR(191) DEFAULT NULL;
CREATE INDEX CONCURRENTLY idx_http_log_path_prefix ON nowo_http_log_entry (path_prefix varchar_pattern_ops, created_at);
UPDATE nowo_http_log_entry SET path_prefix = LEFT(path, 191) WHERE path_prefix IS NULL;
-- same for the route and client IP prefixes of the q / routeName filters:
DROP INDEX CONCURRENTLY idx_http_log_route_name; CREATE INDEX CONCURRENTLY idx_http_log_route_name ON nowo_http_log_entry (route_name varchar_pattern_ops, created_at);
DROP INDEX CONCURRENTLY idx_http_log_client_ip;  CREATE INDEX CONCURRENTLY idx_http_log_client_ip ON nowo_http_log_entry (client_ip varchar_pattern_ops, created_at);

-- SQLite (LIKE is not an index range on SQLite; the column keeps the prefix semantics)
ALTER TABLE nowo_http_log_entry ADD COLUMN path_prefix VARCHAR(191) DEFAULT NULL;
CREATE INDEX idx_http_log_path_prefix ON nowo_http_log_entry (path_prefix, created_at);
UPDATE nowo_http_log_entry SET path_prefix = substr(path, 1, 191) WHERE path_prefix IS NULL;
```

On a large table, run the `UPDATE` in batches (e.g. `... WHERE path_prefix IS NULL AND id BETWEEN :from AND :to`).
Rows whose `path_prefix` is still `NULL` are not matched by the path filter. Going back to `contains` leaves the column
unused (drop it when you no longer need it).

### Template overrides of the list

The list rows passed to `@NowoHttpLogBundle/admin/index.html.twig` (`page.items`, `row` in `nowo_ui_list_row`) are
`Nowo\HttpLogBundle\Model\HttpLogListRow` objects instead of `HttpLogEntry`. Overrides that read `row.id`,
`row.createdAt`, `row.method`, `row.path`, `row.statusCode`, `row.durationMs`, `row.clientIp`, `row.routeName`,
`row.host`, `row.scheme`, `row.userIdentifier`, `row.requestId`, `row.channel` or `row.provider` keep working. Headers,
bodies, query parameters and the other usage columns are no longer loaded for the list: show them on the entry page,
or read the entry by id. Code calling `HttpLogEntryRepository::findListPage()` keeps working (deprecated; use
`findListRows()`).

## To 1.16.3

From **1.16.2**:

```bash
composer update nowo-tech/http-log-bundle
```

No configuration change. Inside your open transactions, the bundle's savepoint is now named
`nowo_http_log_insert_<random>` instead of `nowo_http_log_insert`, and a failed capture after a deadlock reports the
original error.

## To 1.16.2

From **1.16.1**:

```bash
composer update nowo-tech/http-log-bundle
```

No configuration change. Inbound entries are written with a DBAL `INSERT` instead of `persist()` + `flush()` of the
EntityManager that manages `HttpLogEntry` (the default manager in a standard install), so a synchronous capture
(`async: false`, or `PersistHttpLogMessage` handled synchronously) no longer commits the host's unflushed changes at
`kernel.terminate`. Same table, same columns, same values.

What you may notice:

- Doctrine lifecycle events (`prePersist`, `postPersist`, `onFlush`, entity listeners) no longer fire for inbound
  `HttpLogEntry` rows. Usage rows (outbound, mailer) were already written this way.
- Inside a transaction you left open, the insert runs in a savepoint of that transaction (released on success,
  rolled back alone on failure).
- A closed shared EntityManager is no longer reset by the bundle (under FrankenPHP without kernel reset, nothing resets
  it for you any more); reset it in your own error handling as before. A manager that maps only the bundle's entities
  is still reset by `HttpLogEntryRepository`.

## To 1.16.1

From **1.16.0**:

```bash
composer update nowo-tech/http-log-bundle
```

**Move custom credential headers to `redaction.credential_headers` (audit JA2-L1).** In 1.16.0 the resolver guard checked every redacted header with contains/prefix rules, and USAGE told you to list custom credential headers in `redaction.headers`. Since 1.16.1 only the `Authorization` value and the headers in `redaction.credential_headers` get those checks; other redacted headers, query parameters and cookies are compared with the credential id by exact equality only. If your API authenticates with a header such as `X-Api-Key`, add it to `redaction.credential_headers`. A token sent in the query string gets equality only, so make sure your resolver never returns a value derived from it.

**`Authorization` on APIs that authenticate otherwise (JA2-L2).** The contains check applies to the `Authorization` value at any length: on an API that authenticates with another header, a client can add `Authorization: Bearer -` and blank its own tenant and project in the log (no leak). Keep your resolver's values tied to the authenticated credential, or list your real credential header in `credential_headers`.

## To 1.16.0

From **1.15.0**:

```bash
composer update nowo-tech/http-log-bundle
```

From **1.15.0**:

```bash
composer update nowo-tech/http-log-bundle
```

**Nothing changes unless you configure a new block.** With no `channels` and `rollup.enabled: false` (the defaults)
the container gets no listener, subscriber, warmer or schema listener of the new features, the Doctrine mapping and
schema are those of 1.15 (no rollup tables), and web, outbound and mailer captures produce the same payloads and rows.

### Named inbound channels (opt-in)

`nowo_http_log.channels` records requests under path prefixes or routes (e.g. `/api`, `/mcp`, inbound webhooks) as
their own channel, matched **before** the web channel's ignores and sampling (see
[CONFIGURATION](CONFIGURATION.md#channels) and [USAGE](USAGE.md#named-inbound-channels)):

- A named channel maps the same eleven usage columns as `outbound` / `mailer` (the 1.15 DDL below); a host that already
  enabled `outbound` or `mailer` has them. Its rows store the route path template (never the raw path), the route
  name, a truncated client IP by default, the user identifier and the resolver context; never headers, query strings
  or bodies.
- Each named channel is a purge scope with its own `retention_days`, and a channel of the admin filter and of the
  channel access checker (`canViewChannel($user, 'api')`). An access checker that implements
  `HttpLogChannelAccessCheckerInterface` and denies unknown channel names keeps them hidden; one that allows
  everything shows them.
- `X-Request-Id` is neither stored nor used for dedupe on a named channel (server event id).
- Channel prefixes, `ignore_path_prefixes` and the admin UI prefix are compared with the **decoded** path, like the
  router and the firewall (this also applies to hosts without named channels: an encoded `/%61pi/…` is now ignored
  by an `/api` ignore prefix, as it should have been).
- **Lazy firewalls:** the resolver runs before the controller only when that cannot start a lazy firewall's
  authentication; otherwise it runs at `kernel.terminate` (the row gets the context, but outbound calls of the
  controller do not inherit the tenant: wrap them in `HttpLogContext::run()`).
- Route templates come from `var/cache/<env>/nowo_http_log/route_templates.php` (written by a cache warmer, rebuilt in
  debug when a routing file changes). A request whose route the map does not hold (no route, a 404, or a route that
  only matches through a prefix its template does not start with, e.g. `/{_locale}/api/…`) stores `{unrouted}`: list
  such routes in the channel's `routes`.

### Daily rollup (opt-in)

`nowo_http_log.rollup.enabled: true` counts every call of the channels with `rollup: true` (named channels, outbound,
mailer; web with `rollup.web`) in a daily table, exactly once, keyed per UTC day, channel, provider, credential and
tenant. Two DBAL tables are added to the schema of the EntityManager that maps the entry (only while enabled):

1. **Deploy the migration before you enable it.** Enabling without it fails at the first write: the capture is logged
   (one warning) and dropped; the request, the call or the mail is never affected.
2. Generate it with `doctrine:migrations:diff` once enabled, or use the statements below (default names; with
   `doctrine.table_name_prefix: http_log_` replace `nowo_http_log_` with `http_log_`; with `doctrine.table_prefix`
   prefix the tables and every index name). The statements are checked by the test suite: the schema diff is empty
   after them, on MySQL 8.4, MariaDB 11.4, PostgreSQL 16 and SQLite, with default and prefixed names.
3. `rollup.enabled` must be a literal boolean (no `%env()%`).
4. Supported platforms: MySQL / MariaDB, PostgreSQL, SQLite >= 3.24 (`ON CONFLICT`). On another platform every
   rollup write fails and is logged.
5. **Replay the failure transport within `rollup.dedupe_days`** (default 7) of an event's first processing: the
   exactly-once ledger forgets it after that, and a later redelivery would be counted again. A counted channel's
   `retention_days` must be at least `dedupe_days` (its row is the dedupe key; the container refuses to compile
   otherwise).
6. Upgrade workers and web servers together: a count-only event (`row: false`) consumed by a 1.15 worker would be
   written as a row.

MySQL 8 / MariaDB 10.6+ (the key columns are `utf8mb4_bin`, so keys that differ in case are different buckets):

<!-- hl1-rollup-ddl:mysql -->
```sql
CREATE TABLE nowo_http_log_daily (
  id BIGINT AUTO_INCREMENT NOT NULL,
  day DATE NOT NULL,
  channel VARCHAR(16) NOT NULL COLLATE `utf8mb4_bin`,
  provider VARCHAR(64) DEFAULT '' NOT NULL COLLATE `utf8mb4_bin`,
  credential_kind VARCHAR(32) DEFAULT '' NOT NULL COLLATE `utf8mb4_bin`,
  credential_id VARCHAR(64) DEFAULT '' NOT NULL COLLATE `utf8mb4_bin`,
  tenant_key VARCHAR(64) DEFAULT '' NOT NULL COLLATE `utf8mb4_bin`,
  calls BIGINT DEFAULT 0 NOT NULL,
  errors BIGINT DEFAULT 0 NOT NULL,
  transport_errors BIGINT DEFAULT 0 NOT NULL,
  status_2xx BIGINT DEFAULT 0 NOT NULL,
  status_3xx BIGINT DEFAULT 0 NOT NULL,
  status_4xx BIGINT DEFAULT 0 NOT NULL,
  status_5xx BIGINT DEFAULT 0 NOT NULL,
  retries BIGINT DEFAULT 0 NOT NULL,
  bytes BIGINT DEFAULT 0 NOT NULL,
  rows_stored BIGINT DEFAULT 0 NOT NULL,
  duration_ms_sum BIGINT DEFAULT 0 NOT NULL,
  d_le_50 BIGINT DEFAULT 0 NOT NULL,
  d_le_100 BIGINT DEFAULT 0 NOT NULL,
  d_le_250 BIGINT DEFAULT 0 NOT NULL,
  d_le_500 BIGINT DEFAULT 0 NOT NULL,
  d_le_1000 BIGINT DEFAULT 0 NOT NULL,
  d_le_2500 BIGINT DEFAULT 0 NOT NULL,
  d_le_5000 BIGINT DEFAULT 0 NOT NULL,
  d_le_10000 BIGINT DEFAULT 0 NOT NULL,
  d_gt_10000 BIGINT DEFAULT 0 NOT NULL,
  duration_ms_max INT DEFAULT 0 NOT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  UNIQUE INDEX uniq_http_log_daily_key (day, channel, provider, credential_kind, credential_id, tenant_key),
  INDEX idx_http_log_daily_tenant (tenant_key, day),
  INDEX idx_http_log_daily_provider (provider, day),
  INDEX idx_http_log_daily_cred (credential_kind, credential_id, day),
  PRIMARY KEY (id)
);
CREATE TABLE nowo_http_log_event_seen (
  event_id CHAR(32) NOT NULL,
  day DATE NOT NULL,
  INDEX idx_http_log_seen_day (day),
  PRIMARY KEY (event_id)
);
```

PostgreSQL:

<!-- hl1-rollup-ddl:postgresql -->
```sql
CREATE TABLE nowo_http_log_daily (
  id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL,
  day DATE NOT NULL,
  channel VARCHAR(16) NOT NULL,
  provider VARCHAR(64) DEFAULT '' NOT NULL,
  credential_kind VARCHAR(32) DEFAULT '' NOT NULL,
  credential_id VARCHAR(64) DEFAULT '' NOT NULL,
  tenant_key VARCHAR(64) DEFAULT '' NOT NULL,
  calls BIGINT DEFAULT 0 NOT NULL,
  errors BIGINT DEFAULT 0 NOT NULL,
  transport_errors BIGINT DEFAULT 0 NOT NULL,
  status_2xx BIGINT DEFAULT 0 NOT NULL,
  status_3xx BIGINT DEFAULT 0 NOT NULL,
  status_4xx BIGINT DEFAULT 0 NOT NULL,
  status_5xx BIGINT DEFAULT 0 NOT NULL,
  retries BIGINT DEFAULT 0 NOT NULL,
  bytes BIGINT DEFAULT 0 NOT NULL,
  rows_stored BIGINT DEFAULT 0 NOT NULL,
  duration_ms_sum BIGINT DEFAULT 0 NOT NULL,
  d_le_50 BIGINT DEFAULT 0 NOT NULL,
  d_le_100 BIGINT DEFAULT 0 NOT NULL,
  d_le_250 BIGINT DEFAULT 0 NOT NULL,
  d_le_500 BIGINT DEFAULT 0 NOT NULL,
  d_le_1000 BIGINT DEFAULT 0 NOT NULL,
  d_le_2500 BIGINT DEFAULT 0 NOT NULL,
  d_le_5000 BIGINT DEFAULT 0 NOT NULL,
  d_le_10000 BIGINT DEFAULT 0 NOT NULL,
  d_gt_10000 BIGINT DEFAULT 0 NOT NULL,
  duration_ms_max INT DEFAULT 0 NOT NULL,
  first_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
  PRIMARY KEY (id)
);
CREATE UNIQUE INDEX uniq_http_log_daily_key ON nowo_http_log_daily (day, channel, provider, credential_kind, credential_id, tenant_key);
CREATE INDEX idx_http_log_daily_tenant ON nowo_http_log_daily (tenant_key, day);
CREATE INDEX idx_http_log_daily_provider ON nowo_http_log_daily (provider, day);
CREATE INDEX idx_http_log_daily_cred ON nowo_http_log_daily (credential_kind, credential_id, day);
CREATE TABLE nowo_http_log_event_seen (
  event_id CHAR(32) NOT NULL,
  day DATE NOT NULL,
  PRIMARY KEY (event_id)
);
CREATE INDEX idx_http_log_seen_day ON nowo_http_log_event_seen (day);
```

SQLite (>= 3.24):

<!-- hl1-rollup-ddl:sqlite -->
```sql
CREATE TABLE nowo_http_log_daily (
  id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
  day DATE NOT NULL,
  channel VARCHAR(16) NOT NULL,
  provider VARCHAR(64) DEFAULT '' NOT NULL,
  credential_kind VARCHAR(32) DEFAULT '' NOT NULL,
  credential_id VARCHAR(64) DEFAULT '' NOT NULL,
  tenant_key VARCHAR(64) DEFAULT '' NOT NULL,
  calls BIGINT DEFAULT 0 NOT NULL,
  errors BIGINT DEFAULT 0 NOT NULL,
  transport_errors BIGINT DEFAULT 0 NOT NULL,
  status_2xx BIGINT DEFAULT 0 NOT NULL,
  status_3xx BIGINT DEFAULT 0 NOT NULL,
  status_4xx BIGINT DEFAULT 0 NOT NULL,
  status_5xx BIGINT DEFAULT 0 NOT NULL,
  retries BIGINT DEFAULT 0 NOT NULL,
  bytes BIGINT DEFAULT 0 NOT NULL,
  rows_stored BIGINT DEFAULT 0 NOT NULL,
  duration_ms_sum BIGINT DEFAULT 0 NOT NULL,
  d_le_50 BIGINT DEFAULT 0 NOT NULL,
  d_le_100 BIGINT DEFAULT 0 NOT NULL,
  d_le_250 BIGINT DEFAULT 0 NOT NULL,
  d_le_500 BIGINT DEFAULT 0 NOT NULL,
  d_le_1000 BIGINT DEFAULT 0 NOT NULL,
  d_le_2500 BIGINT DEFAULT 0 NOT NULL,
  d_le_5000 BIGINT DEFAULT 0 NOT NULL,
  d_le_10000 BIGINT DEFAULT 0 NOT NULL,
  d_gt_10000 BIGINT DEFAULT 0 NOT NULL,
  duration_ms_max INTEGER DEFAULT 0 NOT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL
);
CREATE UNIQUE INDEX uniq_http_log_daily_key ON nowo_http_log_daily (day, channel, provider, credential_kind, credential_id, tenant_key);
CREATE INDEX idx_http_log_daily_tenant ON nowo_http_log_daily (tenant_key, day);
CREATE INDEX idx_http_log_daily_provider ON nowo_http_log_daily (provider, day);
CREATE INDEX idx_http_log_daily_cred ON nowo_http_log_daily (credential_kind, credential_id, day);
CREATE TABLE nowo_http_log_event_seen (
  event_id CHAR(32) NOT NULL,
  day DATE NOT NULL,
  PRIMARY KEY (event_id)
);
CREATE INDEX idx_http_log_seen_day ON nowo_http_log_event_seen (day);
```

While the rollup tables are mapped, `doctrine.table_prefix` + `doctrine.table_name_prefix` may be at most 48
characters (the longest derived name, PostgreSQL's `…event_seen_pkey`, adds 15).

### Retention and erasure

- **Purge scopes:** `nowo:http-log:purge` (and `PurgeHttpLogMessage` without arguments) now also purges each named
  channel by its `retention_days`, the `rollup` by `rollup.retention_months` (calendar months, the current one
  included: 13 keeps the same month last year) and the `ledger` by `rollup.dedupe_days`. `--channel` accepts
  `web`, each named channel, `outbound`, `mailer`, `rollup` and `ledger`; `--days` with `--channel=rollup` keeps
  buckets of the last N days. A bare `--days` still purges per-call rows only (every channel), never the rollup. The
  admin purge form never touches the rollup or the ledger.
- **Backfill:** `nowo:http-log:rollup:backfill [--from=Y-m-d] [--to=Y-m-d] [--dry-run]` builds buckets from per-call
  rows for days that have none (adoption on a host that already logged rows). Run it once after enabling the rollup.
- **Erasure:** `Nowo\HttpLogBundle\Privacy\HttpLogUsageEraserInterface` (public alias) erases a credential (rows
  deleted, its buckets folded into credential id `~erased`) or a tenant (rows and buckets deleted). The existing
  `HttpLogPersonalDataEraserInterface` also covers named-channel rows (they carry the user identifier and the client
  IP); buckets hold neither.

### New optional APIs

- `Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolverInterface` (autoconfigured, tag
  `nowo_http_log.request_context_resolver`): names the credential and tenant of a named-channel request.
- `Nowo\HttpLogBundle\Usage\HttpLogUsageReaderInterface` (public alias, registered with any usage block): `daily()`,
  `iterateDaily()`, `calls()`, `lastUsed()`, always with an explicit tenant scope.
- `Nowo\HttpLogBundle\Context\HttpLogContext::push()` / `restore()` (used for the request frame of a named channel).

## To 1.15.0

From **1.14.1**:

```bash
composer update nowo-tech/http-log-bundle
```

**Nothing changes unless you enable a new block.** With `outbound` and `mailer` left disabled (the default) the
container gets no listener, decorator or schema listener of the new features, the Doctrine mapping of the entry is
byte-identical to 1.14 (`doctrine:schema:update --dump-sql` is empty) and web requests produce the same message
payload, row, export and admin pages.

### Outbound and mailer capture (opt-in)

`nowo_http_log.outbound` records one allowlisted row per outbound HttpClient call and `nowo_http_log.mailer` one per
mail send attempt (see [CONFIGURATION](CONFIGURATION.md#outbound) and [USAGE](USAGE.md#outbound-and-mailer-usage)).
Enabling either maps eleven nullable columns and five indexes on the entry table:

1. **Deploy the migration before you enable a block.** Enabling without it fails at the first write: the capture is
   logged (one warning, exception class only) and dropped; the HTTP call or the mail is never affected.
2. Generate it with `doctrine:migrations:diff` once the block is enabled, or use the statements below (default table
   name; with `doctrine.table_name_prefix: http_log_`, as Symfony Beacon uses, replace `nowo_http_log_entry` with
   `http_log_entry`; with `doctrine.table_prefix`, prefix the table and every index name). The statements are checked
   by the test suite: the schema diff is empty after them.
3. `enabled` must be a literal boolean (no `%env()%`): it decides the Doctrine mapping at compile time.
4. While the usage columns are mapped, `doctrine.table_prefix` may be at most 34 characters (the longest index name,
   `idx_http_log_provider_created`, has 29).

MySQL 8 / MariaDB 10.6+ (MySQL 8 adds the columns with `ALGORITHM=INSTANT`):

<!-- hl1-ddl:mysql -->
```sql
ALTER TABLE nowo_http_log_entry
  ADD channel VARCHAR(16) DEFAULT NULL,
  ADD provider VARCHAR(64) DEFAULT NULL,
  ADD credential_kind VARCHAR(32) DEFAULT NULL,
  ADD credential_id VARCHAR(64) DEFAULT NULL,
  ADD tenant_key VARCHAR(64) DEFAULT NULL,
  ADD project_key VARCHAR(64) DEFAULT NULL,
  ADD response_bytes BIGINT DEFAULT NULL,
  ADD retry_count SMALLINT DEFAULT NULL,
  ADD error_kind VARCHAR(32) DEFAULT NULL,
  ADD messenger_message_id VARCHAR(64) DEFAULT NULL,
  ADD external_message_id VARCHAR(255) DEFAULT NULL;
CREATE INDEX idx_http_log_chan_created ON nowo_http_log_entry (channel, created_at);
CREATE INDEX idx_http_log_tenant_created ON nowo_http_log_entry (tenant_key, created_at);
CREATE INDEX idx_http_log_project_created ON nowo_http_log_entry (project_key, created_at);
CREATE INDEX idx_http_log_provider_created ON nowo_http_log_entry (provider, created_at);
CREATE INDEX idx_http_log_cred_created ON nowo_http_log_entry (credential_kind, credential_id, created_at);
```

PostgreSQL (on a large table, run each `CREATE INDEX` as `CREATE INDEX CONCURRENTLY` outside a transaction):

<!-- hl1-ddl:postgresql -->
```sql
ALTER TABLE nowo_http_log_entry
  ADD channel VARCHAR(16) DEFAULT NULL,
  ADD provider VARCHAR(64) DEFAULT NULL,
  ADD credential_kind VARCHAR(32) DEFAULT NULL,
  ADD credential_id VARCHAR(64) DEFAULT NULL,
  ADD tenant_key VARCHAR(64) DEFAULT NULL,
  ADD project_key VARCHAR(64) DEFAULT NULL,
  ADD response_bytes BIGINT DEFAULT NULL,
  ADD retry_count SMALLINT DEFAULT NULL,
  ADD error_kind VARCHAR(32) DEFAULT NULL,
  ADD messenger_message_id VARCHAR(64) DEFAULT NULL,
  ADD external_message_id VARCHAR(255) DEFAULT NULL;
CREATE INDEX idx_http_log_chan_created ON nowo_http_log_entry (channel, created_at);
CREATE INDEX idx_http_log_tenant_created ON nowo_http_log_entry (tenant_key, created_at);
CREATE INDEX idx_http_log_project_created ON nowo_http_log_entry (project_key, created_at);
CREATE INDEX idx_http_log_provider_created ON nowo_http_log_entry (provider, created_at);
CREATE INDEX idx_http_log_cred_created ON nowo_http_log_entry (credential_kind, credential_id, created_at);
```

SQLite (one column per statement):

<!-- hl1-ddl:sqlite -->
```sql
ALTER TABLE nowo_http_log_entry ADD COLUMN channel VARCHAR(16) DEFAULT NULL;
ALTER TABLE nowo_http_log_entry ADD COLUMN provider VARCHAR(64) DEFAULT NULL;
ALTER TABLE nowo_http_log_entry ADD COLUMN credential_kind VARCHAR(32) DEFAULT NULL;
ALTER TABLE nowo_http_log_entry ADD COLUMN credential_id VARCHAR(64) DEFAULT NULL;
ALTER TABLE nowo_http_log_entry ADD COLUMN tenant_key VARCHAR(64) DEFAULT NULL;
ALTER TABLE nowo_http_log_entry ADD COLUMN project_key VARCHAR(64) DEFAULT NULL;
ALTER TABLE nowo_http_log_entry ADD COLUMN response_bytes BIGINT DEFAULT NULL;
ALTER TABLE nowo_http_log_entry ADD COLUMN retry_count SMALLINT DEFAULT NULL;
ALTER TABLE nowo_http_log_entry ADD COLUMN error_kind VARCHAR(32) DEFAULT NULL;
ALTER TABLE nowo_http_log_entry ADD COLUMN messenger_message_id VARCHAR(64) DEFAULT NULL;
ALTER TABLE nowo_http_log_entry ADD COLUMN external_message_id VARCHAR(255) DEFAULT NULL;
CREATE INDEX idx_http_log_chan_created ON nowo_http_log_entry (channel, created_at);
CREATE INDEX idx_http_log_tenant_created ON nowo_http_log_entry (tenant_key, created_at);
CREATE INDEX idx_http_log_project_created ON nowo_http_log_entry (project_key, created_at);
CREATE INDEX idx_http_log_provider_created ON nowo_http_log_entry (provider, created_at);
CREATE INDEX idx_http_log_cred_created ON nowo_http_log_entry (credential_kind, credential_id, created_at);
```

### Behaviour once a block is enabled

- **Purge per channel:** `nowo:http-log:purge` (and `PurgeHttpLogMessage` without arguments) purges web rows by
  `retention.days` and outbound / mailer rows by their own `retention_days`, one output line per scope. `--channel`
  (repeatable: `web`, `outbound`, `mailer`) limits it; `--days` together with `--channel` overrides only those scopes.
  A bare `--days` keeps its 1.14 meaning: every row of every channel.
- **Admin UI:** the list gains channel / provider columns and channel, provider, tenant, project and credential-kind
  filters; the entry page shows the allowlisted usage fields; CSV / JSON exports append the usage columns. A host that
  forks `admin/index.html.twig` and enables a block should re-check its `nowo_ui_list_row` override (two more cells).
- **Background exports:** the export store stays shared by every user allowed to export. With a channel access
  checker, an export is listed and served only to users allowed every channel it was built for; exports finished
  before the upgrade (no `.channels` file) count as unrestricted and are hidden from restricted users.
- **Transports and drivers on a decorated client:** list their hosts in `outbound.exclude_hosts` (a transport that
  polls through a decorated client would log every poll).
- **Messenger:** route `Nowo\HttpLogBundle\Message\*` to a dedicated transport with retries and the failure transport
  ([CONFIGURATION](CONFIGURATION.md#messenger-retry-and-failure-transport)); outbound and mailer capture multiply the
  volume of `PersistHttpLogMessage`.

### New optional APIs (available with every block disabled)

- `Nowo\HttpLogBundle\Context\HttpLogContext` (public service): `run($tenantKey, $fn, $projectKey = null)`.
- `nowo_http_log.messenger.context_middleware`: add it to your buses to carry the tenant / project and a stable message
  id to the handler (private service, removed from the container while no bus uses it).
- `Nowo\HttpLogBundle\Security\HttpLogChannelAccessCheckerInterface`: implement it on your access checker to hide
  channels from the admin list, entry page, export and purge.

## To 1.14.1

From **1.14.0**:

```bash
composer update nowo-tech/http-log-bundle
```

Target **1.14.1** (patch). No schema change, no configuration change, no migration.

```bash
composer update nowo-tech/http-log-bundle
```

**Semantic change of `request_id`:** the field keeps its name, type and column (`request_id`, `VARCHAR(64)`, unique,
nullable), and `HttpLogEntry::getRequestId()`, the exports and the admin UI still return it. Its value is now always
generated by the bundle (32 lowercase hex characters, one per logged main request; sub-requests are not logged) and is **never**
copied from the client's `X-Request-Id` header. Before, a client could repeat one `X-Request-Id` on many requests and
make them collapse into one row (the column is unique and the async handler skips an id that already exists).

- **Correlating with a client or upstream id:** the client's `X-Request-Id` is still stored, unchanged, in the
  captured request headers (`request_headers['x-request-id']`) when `capture.request_headers` is on (the default). It
  is never unique and never used for dedupe. Query or filter on the headers instead of `request_id` if you relied on
  `request_id` equalling the header value. The admin search does not cover headers: use the export or a database query for that
  correlation (audit IV-I2).
- **No trusted-upstream option:** the bundle has no setting to trust an upstream request id, and none is added.
- Rows recorded before the upgrade keep their old values; nothing to migrate.

## To 1.14.0

From **1.13.2**:

```bash
composer update nowo-tech/http-log-bundle
```

New option `nowo_http_log.doctrine.table_name_prefix` (default `nowo_http_log_`): it **replaces** the
`nowo_http_log_` part of the table name. With the default nothing changes: the table, index and unique names stay
byte-identical, so no migration is needed. `doctrine.table_prefix` keeps its meaning and is prepended to the result.

**Validation:** both options must match `/^[a-z][a-z0-9_]*\z/` (`table_prefix` was letters, digits and `_` in any
case: an uppercase or digit-first `table_prefix` now fails the container build) and may not make a generated name
longer than 63 characters.

**New:** the `nowo_http_log.doctrine.entry_table` parameter and the public `HttpLogPersonalDataEraserInterface`
service (count / batched delete by user identifier or client IP) for GDPR erasure without SQL.

**Switching an existing database to another `table_name_prefix`** renames the table and the Doctrine-generated
`request_id` unique index (Doctrine derives its name from the table name); the `idx_http_log_*` indexes keep their
names. From `nowo_http_log_entry` to `http_log_entry` (`table_name_prefix: http_log_`, no `table_prefix`); the
statements were checked against MySQL 8.0 and PostgreSQL 17 (the renamed schema matches a fresh install):

Before you run it:

- **Check that no host table already uses the new name** (`http_log_entry`): the rename fails on a clash.
- **Entries recorded during the rename window are dropped**: while the table is being renamed, or while the old code
  runs against the new name (or the reverse), the insert fails. Synchronous recording logs the error and drops the
  entry (requests are never broken); with `async` Messenger retries the message and it may end in the failure
  transport. Deploy the `table_name_prefix` change together with the migration, ideally in maintenance mode, and take a
  backup first. On MySQL / MariaDB the two statements commit separately (`isTransactional(): false` in a Doctrine
  migration); on PostgreSQL run them in one transaction.
- Host code that runs SQL on `nowo_http_log_entry` (GDPR erasers, reports) silently stops matching after the rename:
  switch it to `HttpLogPersonalDataEraserInterface` or to the `%nowo_http_log.doctrine.entry_table%` parameter first.

MySQL 8 / MariaDB ≥ 10.5.2:

```sql
RENAME TABLE nowo_http_log_entry TO http_log_entry;
ALTER TABLE http_log_entry RENAME INDEX UNIQ_A43F86E4427EB8A5 TO UNIQ_6855A9F4427EB8A5;
```

PostgreSQL (the identity sequence and primary key are renamed too so the names match a fresh install):

```sql
ALTER TABLE nowo_http_log_entry RENAME TO http_log_entry;
ALTER SEQUENCE nowo_http_log_entry_id_seq RENAME TO http_log_entry_id_seq;
ALTER INDEX nowo_http_log_entry_pkey RENAME TO http_log_entry_pkey;
ALTER INDEX UNIQ_A43F86E4427EB8A5 RENAME TO UNIQ_6855A9F4427EB8A5;
```

For another prefix, take the new `UNIQ_*` name from `php bin/console doctrine:schema:update --dump-sql` on an empty
database.

## To 1.13.2

From **1.13.1**:

```bash
composer update nowo-tech/http-log-bundle
```

- **Action forms are FormKit Types:** the export / purge / delete forms now use the `http_log` FormKit profile like the
  filter. No change for hosts: field names, CSRF token ids and markup are the same. If you subclass
  `AbstractHttpLogActionType`, your type inherits `#[FormKitConfig('http_log')]` and `FormOptionsTrait`
  (`nowo-tech/form-kit-bundle` stays a required dependency, `NowoFormKitBundle` registered). Customise field classes
  through `nowo_form_kit.profiles.http_log` ([CONFIGURATION](CONFIGURATION.md#form-profile-formkit)), not with a
  `form_theme` in an overridden template.

## To 1.13.1

From **1.13.0**:

```bash
composer update nowo-tech/http-log-bundle
```

From **1.13.0**: no action needed if you import `@NowoHttpLogBundle/Resources/config/routes.yaml` (see
[INSTALLATION](INSTALLATION.md)). If the admin was reachable in your app only through the default
`routing.controllers` import (Symfony >= 7.3), import `routes.yaml` explicitly.

## To 1.13.0

From **1.12.0**:

```bash
composer update nowo-tech/http-log-bundle
```

## To 1.12.0

From **1.11.0**:

```bash
composer update nowo-tech/http-log-bundle
```

### Background exports in a private directory

Admin exports above `export.max_sync_rows` are written to `export.directory` (default
`%kernel.project_dir%/var/http-log/exports`) instead of the system temp directory, and the list header shows a
download button for each finished export until `export.ttl_hours` (default 24) expires.

- The PHP process (Messenger worker and web) must be able to create and write that directory; on several hosts or
  containers, point it to a volume shared by the worker and the web server and not reachable from the web root.
- Delete old `http-log-export-*` files left in the system temp directory by earlier versions.
- `nowo:http-log:export --output=…` now creates the file with mode `0600`.

## To 1.11.0

From **1.10.0**:

```bash
composer update nowo-tech/http-log-bundle
```

### Admin UI requires form CSRF protection at compile time

With `web_ui.enabled: true` the container no longer compiles without form CSRF protection (`LogicException`: "requires CSRF protection"). Standard Flex hosts (`symfony/security-csrf` installed, `framework.csrf_protection` enabled, `framework.form.csrf_protection` not disabled) need no change. Otherwise enable `framework.csrf_protection: true` — also in the `test` environment of functional suites that enable the admin UI — or keep `web_ui.enabled: false`.

## To 1.10.0

From **1.9.1**:

```bash
composer update nowo-tech/http-log-bundle
```

- If you instantiate `HttpLogAdminController` yourself (tests, custom wiring), pass the URL generator as the new last
  constructor argument (`urlGenerator:`); the container autowires it.
- Optional `nowo_http_log.doctrine.table_prefix`: setting it on an existing database renames `nowo_http_log_entry`
  (and its indexes) — ship a migration that renames the table.

## To 1.9.1

From **1.9.0**:

```bash
composer update nowo-tech/http-log-bundle
```

Requires UiKit **^2.2** (`composer require nowo-tech/ui-kit-bundle:^2.2`). No configuration change. What hosts may
notice:

- **Entry page tables**: the header / query-parameter tables come from the UiKit `table` partial — same cells
  (`nowo-http-log-kv__name` / `__value`, `data-nowo-http-log-redacted`), but the `<table>` no longer carries the
  `nowo-http-log-kv` class and gets `aria-label` (the section title). Overrides of `admin/show.html.twig` that call the
  `key_values` macro pass a `View\Table` (`payload.tables.<section>`) instead of the rows.
- **Default theme** (UiKit 2.2): table headers in small uppercase labels, striped rows, subtle badges, the filter
  row on a grid (fields in source order, the Filter button after the last field), solid red confirm button. Hosts that
  styled danger confirms through `btn_danger` set `btn_danger_solid` too (UiKit UPGRADING "Template contract (2.2)").

## To 1.9.0

From **1.8.0**:

```bash
composer update nowo-tech/http-log-bundle
```

- **WebProfiler paths ignored by default (behaviour change):** with `ignore_default_path_prefixes: true` (default) the recorder now also skips `/_wdt/` and `/_profiler/`. You can remove them from your `ignore_path_prefixes` (duplicates are harmless). If you deliberately recorded profiler traffic, set `ignore_default_path_prefixes: false` and list the kit paths you still want skipped (`/_nowo/`, `/sw.js`, `/manifest.webmanifest`).
- **Host with port:** new entries store the host with its port when the request used a non-default one (`getHttpHost()`). Queries or exports that compare `host` with a bare hostname should use a prefix match (`host LIKE 'example.test%'`).
- **Entry page template:** `admin/show.html.twig` overrides `nowo_ui_show_sections` (summary with badges / code slot) and expects a `payload` variable (passed by the bundle controller; `AdminPages::payload($entry)`). A host controller rendering the template without it still gets the 1.8 JSON blocks. Overrides of `nowo_http_log_section_*` blocks keep working. Run `assets:install` so `bundles/nowohttplog/css/nowo-http-log-admin.css` is published.
- **Filter form:** the children of `HttpLogFilterType` are reordered (`q`, `method`, `statusCode`, `clientIp`, `path`, `routeName`, `bodyContentType`, `createdFrom`, `createdTo`); a host `_filter.html.twig` override that renders fields by position should render them by name.

## To 1.8.0

From **1.7.0**:

```bash
composer update nowo-tech/http-log-bundle
```

Standard page contract (REQ-UI-003, UiKit 2.1). Requires `nowo-tech/ui-kit-bundle` **^2.1** (2.1 keeps the CSRF
token of `FormView`-backed header actions when a host `page_header` override renders them, and adds the `code_block`
slot). Minor release: every
1.x key keeps working (deprecated, bridged into the UiKit theme); what changes is the markup of the two admin pages.

```bash
composer update nowo-tech/http-log-bundle nowo-tech/ui-kit-bundle
```

1. **Layout.** The pages now extend UiKit scaffolds, which extend `nowo_ui_kit.layouts.admin` (default
   `@NowoUiKit/layout/admin.html.twig`) — or `nowo_http_log.web_ui.layout_template` when you set it. That layout must
   render the canonical blocks (`nowo_ui_document_title`, `nowo_ui_head`, `nowo_ui_styles`, `nowo_ui_navigation`,
   `nowo_ui_page_header`, `nowo_ui_flashes`, `nowo_ui_content`, `nowo_ui_modals`, `nowo_ui_scripts`) and load
   `nowo-ui.css` + the UiKit scripts (`nowo-ui-confirm.js` opens the purge / delete dialogs). A 1.x bridge that only
   mapped `nowo_ui_content` loses the page header (title, export / purge / back / delete buttons): add
   `nowo_ui_page_header` (and the other blocks), see UiKit `docs/PAGE_CONTRACT.md` "Example: host integration".
   The bundle CDN layout `@NowoHttpLogBundle/layout.html.twig` is gone; that value of `layout_template` is read as
   `null` (UiKit layout).
2. **Overrides.** Re-check every `templates/bundles/NowoHttpLogBundle/` file and every `web_ui.templates.*` target
   against [TEMPLATES.md](TEMPLATES.md) — most can be deleted in favour of `nowo_ui_kit.theme`.
3. **Config.** Remove the deprecated keys below when convenient (they keep working until 2.0).

### Template contract

What hosts may notice:

| 1.x | 1.8 |
| --- | --- |
| `admin/index.html.twig` with its own toolbar, table, `_filter` and `_pagination` | extends `@NowoUiKit/page/list.html.twig`; fills `nowo_ui_list_row` only |
| `admin/show.html.twig` with back link, `<dl class="row">`, `<pre>` sections, delete form | extends `@NowoUiKit/page/show.html.twig`; summary = `DetailSection`, payload cards in `nowo_ui_show_after` |
| Payload `<pre class="nowo-ui-code bg-light p-3">` (`css_classes.code`) | `<pre>` with the UiKit `code_block` slot (`nowo-ui-code-block`, Bootstrap `border p-3`, scrolls above `--nowo-ui-code-block-max-height`) + `nowo-http-log-code` (stable hook, no styles shipped) |
| `layout.html.twig` (Bootstrap CDN), `admin/base.html.twig`, `admin/_pagination.html.twig`, `admin/_macros.html.twig` | **removed** (UiKit layout, scaffold, `pagination` partial, theme slots) |
| `admin/_filter.html.twig` (always included) | deprecated BC partial, used only through `web_ui.templates.filter` (bridged to the theme partial `list_filters`) |
| Twig context `filterForm`, `entries`, `total`, `page` (int), `pageSize`, `exportCsvForm`, `exportJsonForm`, `purgeForm`, `deleteForm`, `nowo_http_log_ui` | `page` = `Nowo\UiKitBundle\View\ListPage` / `ShowPage` (+ `criteria` on the list, `entry` on the detail page) |
| Blocks `nowo_http_log_page_header`, `_intro`, `_filter`, `_toolbar`, `_summary`, `_header_actions`, `_action_export_csv/json`, `_action_purge`, `_table`, `_table_head`, `_table_row`, `_row_actions`, `_empty_state`, `_pagination`, `_details`, `_delete` | removed — use the scaffold data blocks (`nowo_ui_list_row`, `nowo_ui_list_filters`, `nowo_ui_list_after`, `nowo_ui_show_sections`, `nowo_ui_show_after`) or the theme |
| Blocks `nowo_http_log_detail_sections`, `nowo_http_log_section_*` | kept (inside `nowo_ui_show_after`, context `entry`) |
| Blocks `nowo_http_log_filter_panel`, `_fields`, `_actions` | kept in the deprecated `admin/_filter.html.twig`; `filterForm` = `page.filters` |
| Inline `onsubmit="return confirm(…)"` / `confirm_attributes` | UiKit confirm `<dialog>` (no inline JS, CSP-safe) |
| Export / purge / delete forms (`form_start` + hidden criteria) | UiKit `FormView`-backed POST actions (same forms: flat `_token`, CSRF ids `http_log_export`, `http_log_purge`, `http_log_delete`; export posts `format` and the criteria as hidden fields, unchanged); purge / delete confirm in a dialog |
| Export / purge buttons always shown | hidden for users the access checker denies `canExport` / `canPurge` (the routes still enforce it) |
| Twig globals `nowo_http_log_layout_template`, `nowo_http_log_css_framework` | removed (`nowo_http_log_path_prefix`, `nowo_http_log_page_size` kept) |
| Status code as plain text, method badge `badge bg-secondary` | status badges per class (`badge_success` / `_info` / `_warning` / `_danger` slots), method badge slot `badge_neutral` |
| Pagination "Page N of M" | UiKit pagination (numbered, "Showing x–y of n"); filters kept across pages from the request query |

Hosts that tried the unreleased `feature/ui-page-contract` branch on UiKit 2.0: header actions are `FormView`-backed
again and the export route no longer reads `format` / criteria from the URL query (POST fields only, as in 1.x); the
payload `<pre>` gained the `code_block` slot classes. Host `page_header` overrides that render `actions` through
`nowo_ui_partial('action')` keep the CSRF tokens (UiKit 2.1).

A pagination override set with `web_ui.templates.pagination` now receives UiKit's pagination input (`pagination`,
`pagination_model`, `item_count`, `route`, `route_params`, `query`); `pages`, `page_size`, `nav_label`,
`nav_trans_domain` and `framework` are no longer passed.

### Deprecated configuration

| Key | Replacement | Behaviour until 2.0 |
| --- | --- | --- |
| `web_ui.css_framework` | `nowo_ui_kit.css_framework` | seeded into UiKit when set here and not in `nowo_ui_kit` (no longer seeded by default: both default to `bootstrap5`) |
| `web_ui.icon_set` | `nowo_ui_kit.icon_set` | same (both default to `bootstrap-icons`) |
| `web_ui.templates.filter` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.partials.list_filters` | bridged |
| `web_ui.templates.pagination` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.partials.pagination` | bridged (the 1.x default path is ignored) |
| `web_ui.css_classes.*` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.classes.<slot>` | bridged when the value differs from the 1.x default (mapping below) |
| `web_ui.attributes.*` | `nowo_ui_kit.theme.bundles.NowoHttpLogBundle.attributes.<slot>` | bridged (`on*` dropped); `results` / `show` go on the page root |
| `web_ui.ui_kit_stylesheet` | — | no effect (the layout loads `nowo-ui.css`) |
| `web_ui.header_actions_in_layout` | — | no effect (header actions are in `nowo_ui_page_header`) |
| `web_ui.confirm_attributes` | — | no effect (UiKit confirm dialog) |
| `web_ui.ui_kit_row_actions` | `nowo_ui_kit.row_actions_display` | no effect (always the UiKit `row_actions` partial) |
| `Nowo\HttpLogBundle\View\AdminChrome`, parameter `nowo_http_log.web_ui.chrome` | the UiKit theme | kept, unused by the pages |

`css_classes` → slot: `filter`, `filter_form` → `filters`; `btn_filter` → `btn_primary`; `toolbar` → `toolbar`;
`summary` → `page_intro`; `btn_export` → `btn_secondary`; `btn_purge`, `btn_delete` → `btn_danger`; `table_wrapper` →
`table_wrap`; `table`, `table_head_row`, `table_head_cell`, `table_row`, `table_cell` → same name; `method_badge` →
`badge_neutral`; `row_actions` → `row_actions`; `btn_view` → `action_neutral`; `empty_cell` → `empty`; `pagination` →
`pagination`; `page_link` → `pagination_link`; `details` → `detail_list`; `detail_term`, `detail_value` → same name;
`section_title` → `card_title`; `code` → `code_block`. No slot (dropped): `filter_body`, `results`, `actions`,
`action_form`, `action_form_gap`, `path_cell`, `pagination_list`, `page_item`, `show`, `back`, `back_link`,
`delete_form`.
`attributes` → slot: `filter`, `filter_form` → `filters`; `toolbar`; `table_wrapper` → `table_wrap`; `table`;
`details` → `detail_list`; `results` / `show` → page root; `delete_form` dropped.

The bridge is prepended to `nowo_ui_kit`, so your own `nowo_ui_kit.theme.bundles.NowoHttpLogBundle` wins. Values that
were full framework classes (`btn btn-danger`) are usually redundant now: the slot defaults of `nowo_ui_kit.css_framework`
already carry them — move only the host-specific classes into the theme.

## To 1.7.0

From **1.6.0** — kit asset paths ignored by default; keyset batch purge. No action required.

```bash
composer update nowo-tech/http-log-bundle
```

- **Kit ignore paths (`ignore_default_path_prefixes`, default `true`):** requests under `/_nowo/` (HotReloadBundle), `/sw.js` and `/manifest.webmanifest` (PwaBundle) are no longer recorded. They are *merged* with `ignore_path_prefixes` instead of being added to its default list on purpose: Symfony replaces a list default as soon as the host sets the key, so changing the default would have reached only hosts without their own list. With the toggle, every host gets them, and a host that already lists its own prefixes keeps every entry it had (the default of `ignore_path_prefixes` is still `['/admin/http-log']`). You can now remove `/_nowo/`, `/sw.js` and `/manifest.webmanifest` from your `ignore_path_prefixes` (keeping them is harmless: duplicates are removed). To keep recording them, set `ignore_default_path_prefixes: false`.
- **Keyset purge (`retention.purge_batch_size`, default `1000`):** `nowo:http-log:purge`, the admin purge and `PurgeHttpLogMessage` delete at most that many rows per statement. Counts and messages are unchanged. Lower it on busy MySQL primaries / replicas, raise it for faster purges on small instances. If you call `HttpLogEntryRepository::deleteOlderThan()` yourself, it now takes an optional `int $batchSize` (default `1000`, `< 1` throws). The filtered admin purge now uses the same size (was a fixed 500).

## To 1.6.0

From **1.5.1** — no raw FormKit convention keys in the admin filter (requires form-kit ^2.10)

```bash
composer update nowo-tech/http-log-bundle
```

- **FormKit ^2.10 required:** run `composer update nowo-tech/http-log-bundle nowo-tech/form-kit-bundle --with-dependencies`.
- **Filter form help / placeholder:** the seeded `http_log` FormKit profile sets `convention_requires_translation: true`, so the filter fields only show `http_log_filter.<field>.help` / `.placeholder` texts you actually translate (domain `NowoHttpLogBundle`); the raw keys are no longer displayed. If you define your own `nowo_form_kit.profiles.http_log`, nothing is seeded: add `convention_requires_translation: true` (or `auto_help: false` / `auto_placeholder: false`) there to get the same result.

## To 1.5.1

From **1.5.0** — requests under the admin web_ui.path_prefix are never recorded.

```bash
composer update nowo-tech/http-log-bundle
```

- **Admin UI path always ignored:** while `web_ui.enabled` is true, requests under `web_ui.path_prefix` (segment-aware) are never recorded, even if the prefix is not listed in `ignore_path_prefixes`. Adding it there manually is no longer needed (harmless if kept). If you deliberately logged admin UI traffic (by overriding both `ignore_path_prefixes` and `ignore_routes`), that traffic is no longer recorded.

## To 1.5.0

From **1.4.0** — fixes - findOneBy field whitelist, path_prefix/export.formats/icon_set wired, Symfony >=7.4.

```bash
composer update nowo-tech/http-log-bundle
```

- **Symfony 7.4+:** the minimum Symfony version is now 7.4 (7.0–7.3 are end of life and were never really tested).
- **Admin route prefix:** `web_ui.path_prefix` now moves the admin routes. If you set it to something other than `/admin/http-log` while keeping the UI there, either remove the key or update your `access_control` / links to the new path. The recorder skips `web_ui.path_prefix` automatically (since the next release after 1.5.0; on 1.5.0 itself add the new prefix to `ignore_path_prefixes` or keep `nowo_http_log_*` in `ignore_routes`). If you import the routes with your own `prefix:`, it is added in front of `path_prefix`.
- **Export formats:** `export.formats` now limits the UI buttons, the export action and `nowo:http-log:export`. Only `csv` and `json` are accepted, and the list cannot be empty.
- **`findOneBy()` on `HttpLogEntryRepository`:** unknown field names now throw `InvalidArgumentException` instead of producing DQL.
- **Templates:** if you override `admin/index.html.twig`, `exportCsvForm` / `exportJsonForm` can be `null` when that format is disabled; guard with `{% if exportCsvForm %}`.

## To 1.4.0

From **1.3.1** — security - redact before truncation, redact form bodies, fail closed on empty access_roles.

```bash
composer update nowo-tech/http-log-bundle
```

- **Behaviour change:** `security.access_roles: []` used to grant access to everyone (including anonymous users); it now denies everyone. Set roles, or use `allow_unauthenticated: true` / a custom `access_checker` to open the admin UI on purpose.
- Bodies are now redacted before truncation; form-urlencoded and multipart text fields are redacted by key, and JSON-looking bodies that cannot be parsed are stored as `[REDACTED-UNPARSEABLE]`. Default redacted keys now include `_password`, `_token` and `_csrf_token`, so some stored bodies will differ.
- A negative or non-integer `older_than_days` on purge now returns HTTP 400 (the purge command rejects negative `--days`).

## To 1.3.1

From **1.3.0** — dependency refresh and repository maintenance.

```bash
composer update nowo-tech/http-log-bundle
```

- No breaking changes. No application upgrade steps.

## To 1.3.0

From **1.2.0** — admin chrome hooks (blocks, class map, partial paths) and a pagination fix. No breaking change: with no new keys the admin pages render the same markup, except that pagination links now keep the active filters.

```bash
composer update nowo-tech/http-log-bundle
php bin/console cache:clear
```

- **Nothing to do** if you use the bundle templates as they are.
- **You forked `admin/index.html.twig`, `admin/show.html.twig` or `admin/_filter.html.twig`:** your copies keep working (same variables, routes, CSRF ids). Review them against [USAGE.md — Customising the admin chrome](USAGE.md#customising-the-admin-chrome): most chrome-only forks can be deleted in favour of `web_ui.css_classes` / `web_ui.attributes` / `web_ui.templates` / `header_actions_in_layout` / `confirm_attributes`, or shrunk to `{% extends '@!NowoHttpLogBundle/admin/index.html.twig' %}` plus one or two `nowo_http_log_*` blocks.
- **Host layout with a page-header actions slot:** set `web_ui.header_actions_in_layout: true` and render `{{ block('nowo_http_log_header_actions') }}` (guarded by `is defined`) from your layout or bridge.
- **Strict CSP:** set `web_ui.confirm_attributes` (e.g. `data-controller`, `data-action`, `data-…-message-value: '{message}'`) to drop the inline `onsubmit` handlers.
- **Flash messages** are now translated (`flash.*` keys). If you asserted the English texts in other locales, update the assertions or override the keys in `translations/NowoHttpLogBundle.<locale>.yaml`. The de / fr / it / nl / pt admin strings are now translated too; override keys you want to keep in English.
- **Pagination now keeps the filters** (bug fix): links carry `http_log_filter[field]=value` instead of flat `field=value`. If you forked `admin/index.html.twig` or wrote your own pagination partial, build the links from the `query` variable the list page now passes to `web_ui.templates.pagination` (`{http_log_filter: {…}}`, dates as `Y-m-d\TH:i`) — or switch to the bundle page plus `templates.pagination` — otherwise page 2 still loses the filters. Bookmarked URLs with the old flat keys simply open unfiltered, as before.
- `ui_kit_row_actions: true` needs `nowo-tech/ui-kit-bundle` >= 1.6 (`_row_actions` partial).

## To 1.2.0

From **1.1.7** — new optional config key, Doctrine ORM floor raised.

```bash
composer update nowo-tech/http-log-bundle
php bin/console cache:clear
```

- **Doctrine ORM:** the bundle now requires `doctrine/orm` `^3.7` (it uses the `SortDirection` enum). Apps still on ORM 2.x or < 3.7 must upgrade ORM first.
- **New option `ignore_unrouted`** (default `false`, no behaviour change): set it to `true` to stop logging requests no route matched (scanner 404s such as `/wp-login.php`, `/.env`):

```yaml
nowo_http_log:
    ignore_unrouted: true
```

## To 1.1.7

From **1.1.6** — REQ-CS-008 Igor FrankenPHP worker audit (igor-php require-dev, igor.json, make igor).

```bash
composer update nowo-tech/http-log-bundle
php bin/console cache:clear
```

- No application upgrade steps for require-dev Igor tooling (REQ-CS-008). Consumers do not pull `igor-php/igor-php` transitively.

## 1.1.6

FrankenPHP / long-lived kernel (worker mode **without** kernel reset). **No configuration key changes.**

```bash
composer update nowo-tech/http-log-bundle
php bin/console cache:clear
```

Behaviour changes:

- `userIdentifier` is stored only when the request matched a firewall with security enabled. Requests outside any firewall or under `security: false` are logged without a user (in classic PHP-FPM they never had one).
- `HttpLogRecorder::persistCapture()` detaches the entry after flushing and may reset a closed EntityManager via `ManagerRegistry::resetManager()`. Decorators or listeners that expected the new `HttpLogEntry` to stay managed after the flush must re-fetch it from the repository.
- `HttpLogRecorder` has two new optional trailing constructor arguments (`$managerRegistry`, `$security`); code constructing it manually keeps working.
- `HttpLogEntryRepository` resolves the EntityManager from the registry on every call. Admin list/detail and export detach loaded rows after use; purge selects ids only.

See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## From 1.1.4 to 1.1.5

No breaking changes. **No application upgrade steps.**

```bash
composer update nowo-tech/http-log-bundle
```

## 1.1.4 (Flex when@prod PII + access_control)

Review Flex recipe `when@prod` retention/sampling and `security_nowo_http_log.yaml` after update. Schedule `nowo:http-log:purge` if not already.

```bash
composer update nowo-tech/http-log-bundle
```

## 1.1.3 (maintainer lock refresh)

No application upgrade steps.

```bash
composer update nowo-tech/http-log-bundle
```

## 1.1.2 (composer audit CI)

No application upgrade steps.

```bash
composer update nowo-tech/http-log-bundle
php bin/console cache:clear
```

## 1.1.1 (Symfony 8 demos / Hot Reload 1.4)

- No application upgrade steps. **Demos only:** Hot Reload Bundle `^1.4` (FrankenPHP Mercure/`hot_reload`, `dev`/`test`).

## 1.1.0

From **1.0.2** — Adds FormKit, UiKit, Twig Extra (REQ-TWIG-004), and Twig-CS-Fixer. Register `TwigExtraBundle`, `NowoFormKitBundle`, and `NowoUiKitBundle` if Flex did not. See [CHANGELOG](CHANGELOG.md).

```bash
composer update nowo-tech/http-log-bundle
php bin/console assets:install
php bin/console cache:clear
```

### UiKitBundle (REQ-UI-001-kit)

Admin UI depends on **[UiKitBundle](https://github.com/nowo-tech/UiKitBundle)** (`nowo-tech/ui-kit-bundle` `^1.4`).

1. The package is pulled transitively; run `assets:install` so `css/nowo-ui.css` is available under the `nowo_ui_kit` package.
2. Stylesheet: `asset('css/nowo-ui.css', 'nowo_ui_kit')` via `admin/base.html.twig`.
3. Optional: set `nowo_ui_kit.css_framework` / `icon_set` in the host. If unset, HttpLog seeds those keys from `web_ui.css_framework` and defaults `icon_set` to `bootstrap-icons`.
4. Template overrides: extend `@NowoHttpLogBundle/admin/base.html.twig` and prefer `ui.flash()` / `ui.btn()` over hard-coded Bootstrap alert/button classes.

### FormKitBundle (admin forms)

Ensure `nowo-tech/form-kit-bundle` ^2.0 is installed (pulled transitively) and `Nowo\FormKitBundle\NowoFormKitBundle` is registered. Form types use profile `http_log` via `#[FormKitConfig]`; the bundle prepends that profile when the host has not defined it.

### Twig Extra Bundle (REQ-TWIG-004)

Hosts that render this bundle's Twig templates must install:

```bash
composer require twig/extra-bundle twig/string-extra
```

and enable `Twig\Extra\TwigExtraBundle\TwigExtraBundle`. Flex recipes usually register it automatically.

### Twig-CS-Fixer (maintainers)

Package maintainers: `composer twig:lint` / `composer twig:fix` use `.twig-cs-fixer.php` over `src/` (and `templates/` when present).

## 1.0.2

Admin UI layout stacking (REQ-UI-001). Configuration keys and public PHP API are unchanged.

```bash
composer update nowo-tech/http-log-bundle
```

### Behaviour

- List/detail templates extend `@NowoHttpLogBundle/admin/base.html.twig`, which extends `web_ui.layout_template` and stacks host `stylesheets` / `javascripts` with `{{ parent() }}`.
- Default demo `layout.html.twig` asset blocks are named `stylesheets` / `javascripts` (Symfony convention). Nested hooks `nowo_ui_styles` / `nowo_ui_scripts` remain on the base shell. Content block `nowo_ui_content` is unchanged.

### Action required only if you customized Twig

| Situation | What to do |
| --- | --- |
| Default bundle layout (no overrides) | None — clear cache if admin CSS/JS looks wrong. |
| Frozen override of `layout.html.twig` that used `nowo_ui_styles` / `nowo_ui_scripts` as top-level blocks | Rename those blocks to `stylesheets` / `javascripts`, or remove the override and rely on config. |
| Custom `web_ui.layout_template` (host layout / bridge) | Ensure the template defines `stylesheets` and `javascripts` (and a place for `nowo_ui_content`, or a thin bridge that maps it into your `body` block). |
| Frozen overrides of `admin/index.html.twig` / `admin/show.html.twig` | Optional: change `{% extends … %}` to `@NowoHttpLogBundle/admin/base.html.twig` so host assets stack correctly. |

See [CONFIGURATION.md — web_ui](CONFIGURATION.md#web_ui) and [USAGE.md — Twig template overrides](USAGE.md#twig-template-overrides).

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
