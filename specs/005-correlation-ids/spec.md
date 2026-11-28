# Correlation ids — Http Log Bundle

**Package:** `nowo-tech/http-log-bundle`  
**Namespace:** `Nowo\HttpLogBundle`  
**Config alias:** `nowo_http_log.correlation`  
**Status:** Implemented (unreleased, planned 1.27.0)  
**Origin:** symfony-beacon spec 235 (traceability) T002, FR-002 / FR-005; contract package
`nowo-tech/correlation-contract` ^0.1

## Overview

A host that owns a correlation id per unit of work (request, message, command) exposes it through
`Nowo\CorrelationContract\CorrelationIdProviderInterface`. HttpLog uses that id as the correlation id of its rows
(never as the unique `request_id`), and (opt-in) stores it on every row with the id of a trusted upstream proxy, so one
search shows a request with its outbound calls and mails. A host without a provider and without the option sees no
change.

## User scenarios (`US-*`)

### US-01 — One id for the whole request (Priority: P1)

As a host operator, the id my application logs, audits and returns finds the HttpLog rows of that request.

**Acceptance:** With a registered provider, the rows' correlation id (`HttpLogEntryRecordedEvent::$correlationId`,
`correlation_id`) equals the provider's current id, also when the provider sets it after HttpLog's `kernel.request`
listener. `request_id` is generated exactly as in 1.26 in every case: two requests with the same provider id give two
rows with distinct `request_id` and the same `correlation_id`.

### US-02 — Find what a request did (Priority: P1)

As an admin, filtering the log by a correlation id lists the request, the outbound calls and the mails it caused.

**Acceptance:** With `correlation.columns: true`, web, named-channel, outbound and mailer rows recorded during the
request carry the same `correlation_id`; the admin list filters on it exactly; the export carries it.

### US-03 — Cross-system search without trusting clients (Priority: P2)

As an operator behind a load balancer, I can search by the id my proxy sent, and an untrusted direct client can never
inject one.

**Acceptance:** `upstream_id` holds the first valid `X-Request-Id` / `X-Correlation-Id` / `traceparent` trace-id only
when `Request::isFromTrustedProxy()`; untrusted or invalid values are dropped silently; it never replaces `request_id`.
The proxy must overwrite the configured headers for the value to be the proxy's (documented).

## Functional requirements (`FR-*`)

| ID | Requirement |
| --- | --- |
| FR-CORR-001 | `correlation.use_provider` (default `true`): `Correlation\CorrelationIds` reads the host's `CorrelationIdProviderInterface` service when one is registered (`NULL_ON_INVALID_REFERENCE`; HttpLog registers none). `HttpLogSubscriber` keeps its current id in `ATTR_CORRELATION_ID` on the main request (read at `kernel.request` 1024, `kernel.response` -1024 and `kernel.terminate` -1024; the latest non-null id wins). `ATTR_REQUEST_ID` / `request_id` is always generated as in 1.26, never the provider's id. A failing provider is ignored. |
| FR-CORR-002 | `correlation.columns` (default `false`, literal boolean): `Doctrine\CorrelationColumnsListener` maps nullable `correlation_id` / `upstream_id` (`VARCHAR(64)`) and the indexes `idx_http_log_corr_id` / `idx_http_log_upstream` (`… , created_at`); exact-match collation on MySQL / MariaDB. Not registered while off (no migration). Every reader / writer checks `hasField`. |
| FR-CORR-003 | `correlation_id`: web and named inbound channel rows = the provider's id of the request, else the request's `request_id`; outbound and mailer rows = `HttpLogContext::correlationIdAtCapture()`: the provider's id at the start, else the provider's id when recorded, else the request's correlation id at the start, else null (at `kernel.terminate` the main request is off the stack). `request_id` stays the unique dedupe key of every row. |
| FR-CORR-004 | `upstream_id`: with `columns`, the first header of `correlation.upstream_headers` (default `X-Request-Id`, `X-Correlation-Id`, `traceparent`; at most 4; a later config file replaces the list) that parses (`CorrelationId::tryFromString`, `traceparent` → `tryFromTraceparent`), only when the main request's `REMOTE_ADDR` is a trusted proxy (the proxy must overwrite the headers); kept in the request attribute `ATTR_UPSTREAM_ID` (worker safe), stored on web and named-channel rows. Raw header values are never logged. |
| FR-CORR-005 | Admin: exact `correlationId` / `upstreamId` filters (form option `correlation`), both ids on the entry page, kept by the export criteria. Export: `correlationId`, `upstreamId` appended last to CSV (formula-neutralised cells) and JSON. |
| FR-CORR-006 | `HttpLogEntryRecordedEvent`, `UsageCapture` (payload keys only when set, re-validated by `fromArray`), `HttpLogCapture` (`upstreamId`) carry the ids; every value passes `CorrelationId::PATTERN`. |
| FR-CORR-007 | HttpLog adds no response header (it owns none); echoing `X-Request-Id` stays with the provider's integration. |

## Non-goals

- Generating or propagating correlation ids (the host's provider and Messenger stamp do that).
- Replacing `request_id` with a non-unique key, or storing the upstream id on outbound / mailer rows.

## Success criteria

| ID | Criterion |
| --- | --- |
| SC-01 | Unit tests: `CorrelationIdsTest`, `HttpLogSubscriberCorrelationTest`, `CorrelationExtensionTest`, capture / guard / context / inbound recorder tests; repeated provider id (sync and async: distinct `request_id`, same `correlation_id`), late provider picked up at capture, throwing provider at `kernel.response`; 100 % line coverage |
| SC-02 | Integration: `CorrelationColumnsTest` (SQLite: mapping, writes, filters, events, exports), documented DDL leaves no schema diff on SQLite / MySQL / PostgreSQL (`UsageSchemaTest`), exact collation on MySQL / MariaDB (`ExactCollationTest`) |
| SC-03 | `CorrelationColumnsDbTest` (`database` group) passes on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, DBAL 3 and 4 |
| SC-04 | No change for a host without provider and with `columns: false` (existing suites unchanged) |
