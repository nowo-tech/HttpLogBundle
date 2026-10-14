# Kit ignore paths and keyset purge — Http Log Bundle

**Package:** `nowo-tech/http-log-bundle`  
**Namespace:** `Nowo\HttpLogBundle`  
**Config alias:** `nowo_http_log`  
**Status:** Released in 1.7.0  
**Origin:** Symfony Beacon `docs/ops/KIT-EXTRACTION.md` row #19 (kit asset paths in the default ignore list) and the
"fix them too" note of row #21 (keyset batch delete for retention purges)

## Overview

Two host-side workarounds move into the bundle:

1. Every app using HotReloadBundle or PwaBundle had to add `/_nowo/`, `/sw.js` and `/manifest.webmanifest` to
   `ignore_path_prefixes` (HotReload alone logs two requests per dev page view). 1.7.0 skips them by default through
   a separate toggle, merged with the host list, so hosts that already set their own list keep every entry they had.
2. The retention purge (`HttpLogEntryRepository::deleteOlderThan()`) deleted every expired row in one unbounded
   `DELETE`, locking the table and writing one huge binlog event on large logs. It now deletes in keyset batches.

## User scenarios (`US-*`)

### US-01 — Kit assets are not logged (Priority: P1)

As a host integrator using HotReloadBundle / PwaBundle, I do not want their internal requests in the HTTP log, without
listing their paths myself.

**Acceptance:** With default configuration (or any custom `ignore_path_prefixes`), requests under `/_nowo/`, `/sw.js`
and `/manifest.webmanifest` are not recorded. `ignore_default_path_prefixes: false` restores recording them.

### US-02 — Purging a large log is safe (Priority: P1)

As an operator, I purge millions of expired rows without locking the table or flooding replication.

**Acceptance:** `nowo:http-log:purge`, the admin purge and `PurgeHttpLogMessage` delete in statements of at most
`retention.purge_batch_size` rows and still report the total number of rows deleted.

## Functional requirements (`FR-*`)

| ID | Requirement |
| --- | --- |
| FR-KIP-001 | New boolean `ignore_default_path_prefixes` (default `true`). While true, `Configuration::DEFAULT_IGNORE_PATH_PREFIXES` (`/_nowo/`, `/sw.js`, `/manifest.webmanifest`) are appended to `ignore_path_prefixes`; host entries come first and duplicates are removed. The merged list is the `nowo_http_log.ignore_path_prefixes` parameter injected into `HttpLogSubscriber`. |
| FR-KIP-002 | The default of `ignore_path_prefixes` stays `['/admin/http-log']`, so a host that sets its own list keeps exactly the entries it had (plus the kit defaults); no entry is ever removed by the upgrade. |
| FR-PUR-002 | New `retention.purge_batch_size` (integer, default `1000`, min `1`). |
| FR-PUR-003 | `deleteOlderThan(DateTimeImmutable $before, int $batchSize = 1000)` loops: select up to `$batchSize` ids with `createdAt < :before AND id > :afterId ORDER BY id` (platform limit via `setMaxResults`), delete that id list (`deleteByIds()`), move `afterId` to the last id, stop on an empty or short batch. Returns the total rows deleted; a batch size `< 1` throws `InvalidArgumentException`. |
| FR-PUR-004 | `PurgeHttpLogService` passes `retention.purge_batch_size` to `deleteOlderThan()` and uses it for the criteria purge id batches (was a fixed 500). Dry runs, return values, the purge command output and the message handler log are unchanged. |

## Non-goals

- A dependency on AuditKitBundle (`Nowo\AuditKitBundle\Doctrine\KeysetDelete`): the batch loop is implemented locally.
- Segment-aware matching for `ignore_path_prefixes` (still `str_starts_with`).

## Success criteria

| ID | Criterion |
| --- | --- |
| SC-01 | `NowoHttpLogExtensionTest` covers default merge, host list + missing defaults (host order kept, duplicates removed), and `ignore_default_path_prefixes: false` |
| SC-02 | `HttpLogEntryRepositoryTest` (SQLite) deletes 7 expired rows in batches of 3 (short batch stop) and 4 rows in batches of 2 (empty-batch stop), keeps recent rows, rejects batch size 0 |
| SC-03 | `PurgeHttpLogServiceTest` asserts the configured / default batch size reaches the repository |
| SC-04 | Coverage stays at 100 % lines; `make release-check` green |

## Validation

```bash
make release-check
```

## Related

- [001-baseline](../001-baseline/spec.md) — FR-IGN-001, FR-PUR-001
- [docs/CONFIGURATION.md](../../docs/CONFIGURATION.md) — `ignore_default_path_prefixes`, `retention.purge_batch_size`
- [docs/UPGRADING.md — To 1.7.0](../../docs/UPGRADING.md#to-170)
