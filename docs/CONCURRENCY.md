# Concurrency

How the bundle stays correct when two requests, two workers or a redelivered message write the same rows at the same
time (Nowo bundle requirement REQ-RUNTIME-003). Applies from 1.22. Worker mode with the kernel reset off is covered in
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## Table of contents

- [Principles](#principles)
- [Write paths](#write-paths)
- [Conflict answers and codes](#conflict-answers-and-codes)
- [Host options](#host-options)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## Principles

- **A log write never breaks the host request.** The capture of a host request, an outbound call or a mail runs on
  the host's path. Its writes are DBAL statements built from the entity metadata: the bundle never persists, flushes,
  clears or resets the host's entity manager. A failed sync capture is logged (`error` for a web entry, `warning` for
  a usage row) and never thrown into the host's code. With `async: true` (the default), the write runs in a Messenger
  handler instead, and a lock conflict there is rethrown, so Messenger retries it.
- **Savepoints inside the host's transaction.** When the host left a transaction open, every bundle write runs in its
  own `SAVEPOINT` with a name unique per call (`LogWriteUnit`). A failed write rolls back only itself. So the host's
  transaction stays usable on PostgreSQL (no SQLSTATE 25P02), and DBAL 3 without nested savepoints never marks it
  rollback-only.
- **A lost savepoint is never success.** When the server already ended the host's transaction (a MySQL / MariaDB
  deadlock, or a lock wait timeout with `innodb_rollback_on_timeout`), the savepoint is gone. The bundle then marks
  the host's transaction rollback-only before it logs the original error. The host's `commit()` fails, instead of
  reporting as saved a unit whose first part the server rolled back.
- **One transaction per write unit.** A rollup count (the per-call row or the ledger row, then the bucket upsert), a
  credential fold and a backfill day each run in one transaction. Outside a host transaction the rollup units go
  through nowo-tech/transaction-doctrine's connection runner. It repairs the nesting level after a failure and
  rethrows a lock conflict as `Nowo\TransactionContract\TransientStorageFailure`. A deadlock or serialization failure
  of that own transaction is replayed at most twice, since nothing of it was committed and every unit is idempotent.
  A lock wait timeout is never replayed, so a log write never waits twice.
- **Natural keys make every replay safe.** The web entry's `request_id` and the usage row's event id are unique, and
  so is the ledger's `event_id`. A redelivered message, or a replay after a conflict, writes nothing twice.
- **Counters are atomic.** Rollup buckets are upserts with `calls = calls + 1` (and the other counters) in SQL, never
  a read-modify-write. `MAX()` appears only in read aggregates.
- **Only bundle-owned managers are reset.** The repository resets a closed entity manager only when it maps the
  bundle's entities alone (REQ-RUNTIME-002). The host's manager is never reset or cleared.

## Write paths

| Path | Transaction | Control (rule 4) | Conflict answer (rule 6) |
| --- | --- | --- | --- |
| Web capture, sync (`HttpLogSubscriber` → `HttpLogRecorder::persistCapture()`, at `kernel.terminate`) | One `INSERT` in auto-commit, or in a savepoint inside the host's open transaction | Unique `request_id` | None: the host request already has its answer. The failure is logged (`Failed to record HTTP log entry.`). A lost savepoint marks the host's transaction rollback-only. |
| Web capture, async (`PersistHttpLogHandler`) | One `INSERT` (as above) | Look-up by `request_id`, then insert. The unique `request_id` catches a race: `UniqueConstraintViolationException` = already stored. | A lock conflict is rethrown, so Messenger retries. The replay finds the row and writes nothing. |
| Usage row, sync (outbound calls, mails, named channels: `HttpLogBuffer` → `UsageEntryWriter`) | One `INSERT`, in a savepoint inside the host's transaction. Written at the request / command / message boundary, or after 100 buffered captures. | Unique event id (`request_id` column): a duplicate means already stored | None: logged (`HTTP log usage capture dropped.`) and dropped. |
| Usage row, async (`PersistHttpLogHandler::persistUsage()`) | As above | As above | Rethrown, so Messenger retries |
| Rollup count (`UsageRollupStore::record()`) | One unit: per-call row or ledger row, then the bucket upsert. Its own transaction (runner, replayed twice on deadlock / serialization failure), or a savepoint inside the host's. | Unique `request_id` / `event_id` ledger (exactly once). Atomic upsert of the bucket. | Sync: logged and dropped. Async: rethrown (`TransientStorageFailure`), so Messenger retries. |
| Credential erasure fold (`HttpLogUsageEraser::eraseCredential()` → `UsageRollupStore::foldCredential()`) | One unit: the buckets are read `FOR UPDATE` (ordered by id), folded into `~erased`, then deleted | Row locks: a concurrent count waits for the fold, then starts a new bucket | Rethrown to the caller |
| Tenant erasure (`eraseTenant()`) and personal-data erasure (`HttpLogPersonalDataErasure`) | The caller's transaction, or one of its own (`transactional()`). Rows deleted by id in keyset batches. | Exact match in PHP after the SQL pre-filter (REQ-SEC-006). Deletes by id are idempotent. | Rethrown to the caller (UserKit or the host) |
| Admin delete (`POST {prefix}/{id}/delete`) | One `DELETE` by id | Idempotent | **409** `CONFLICT_001` + `Retry-After` |
| Admin purge (`POST {prefix}/purge`, sync) and `PurgeHttpLogService` | Batches of `purge_batch_size` ids, one `DELETE` each, in auto-commit | Idempotent: a batch deleted twice deletes nothing the second time | **409** `CONFLICT_002` without `Retry-After` once a batch is deleted, else `CONFLICT_001` |
| Purge, async (`PurgeHttpLogHandler`) and `nowo:http-log:purge` | As above | As above | Rethrown (Messenger retry, or the command fails). Batches already deleted stay deleted. |
| Rollup and ledger purge (`purgeBuckets()`, `purgeLedger()`) | Keyset batches, one `DELETE` each | Idempotent | Rethrown |
| Admin export (`POST {prefix}/export`) | No database write, except the Messenger dispatch of `ExportHttpLogMessage` | — | **409** if the dispatch hits a lock conflict |
| Rollup backfill (`nowo:http-log:rollup:backfill`) | One transaction per day and channel | Skips a day that already has buckets; never today (counted live) | Rethrown: the command fails, and the days already written stay written |

Read-only paths: the admin list and show pages, the usage reader, the personal-data exporter and
`nowo:http-log:export`.

## Conflict answers and codes

A **lock conflict** is a deadlock, a lock wait timeout or a serialization failure: MySQL 1213 / 1205, MariaDB 1020,
PostgreSQL 40001 / 40P01 / 55P03. It may come wrapped in `TransientStorageFailure`, or be a lost savepoint whose commit
outcome is unknown. On the bundle's admin routes it answers as follows:

| Code | When | Status | Headers | Client |
| --- | --- | --- | --- | --- |
| `CONFLICT_001` | Nothing of the request was committed | 409 (or the configured 503) | `X-Conflict-Code: CONFLICT_001`, `Retry-After: <seconds>` | Retrying is safe. |
| `CONFLICT_002` | Something was committed earlier in the same request (a purge batch), or the commit outcome is unknown | 409 (or 503) | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` | Reload the page, do not repeat blindly. |

- The page is the application's error page (an `HttpException` with a fixed, client-safe message; the storage error
  stays as its previous exception, for the logs).
- `X-Conflict-Code` carries only these two values.
- **A purge batch already deleted:** the purge deletes in batches, each committed on its own. When a batch meets a
  lock conflict after an earlier batch of the same call was deleted, the purge rethrows it as transaction-doctrine's
  `ConcurrencyConflict` with `committed: true`, so the answer is `CONFLICT_002` without any DBAL middleware (since
  1.23).
- **Other commits earlier in the same request** use transaction-doctrine's request watermark
  (`ConflictCode::forRequest()`) when the host registers `NowoTransactionDoctrineBundle` with `commit_detection` on.
  Without it, `conflict_response.commit_tracking: bundle` makes the bundle count the commits of the connections of the
  entity managers that map the entries itself (opt-in since 1.23; the default `off` wraps no host connection). The
  bundle's admin routes commit nothing else, so the default answers correctly unless your own listeners write during
  them.
- **Not conflicts:** unique-constraint violations and lost connections are never mapped. A unique violation on the
  bundle's keys is expected only for a replayed capture, and is handled there as "already stored". Anything else is a
  real error (500).
- The bundle answers only on its own routes. The capture of host requests never throws into them.

## Host options

```yaml
nowo_http_log:
    async: true                 # default: captures are written by a Messenger handler, which retries lock conflicts
    conflict_response:
        status: null            # 409 (default) or 503; null: transaction-doctrine's conflict_response, else 409
        retry_after: null       # seconds for CONFLICT_001; null: transaction-doctrine's, else 1
        commit_tracking: off    # off (default) | bundle | all (see CONFIGURATION.md)
    retention:
        purge_batch_size: 1000  # rows per purge statement: smaller batches hold fewer locks
```

- Route `PersistHttpLogMessage` to an async transport with retries and a failure transport
  ([CONFIGURATION.md](CONFIGURATION.md#messenger-retry-and-failure-transport)), so a lock conflict delays a log row
  instead of dropping it.
- MySQL and MariaDB wait `innodb_lock_wait_timeout` (50 s by default) for a row lock. A capture inserts new rows and
  waits only for a concurrent writer of the same request id or bucket. Lower the timeout per connection if a worker
  must give up sooner. PostgreSQL waits `lock_timeout` (no limit by default).
- For `CONFLICT_002` across the host's own connections, register nowo-tech/transaction-doctrine's Symfony bundle with
  `commit_detection: true`.

## Tests

- `tests/Integration/Concurrency/ConcurrentWritesDbTest.php` uses two connections on MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 16, in the CI job "Real databases" (DBAL 3 and 4, a required check). Both sessions wait at most 1 s for a
  lock. Each case asserts the answer, the stored rows and that the connection is still usable:
  - one request id written by two workers: a lock conflict (never a duplicate), then a replay that writes nothing;
  - one event counted by two workers: counted once;
  - two events of one bucket: both counted (no lost update);
  - a sync capture blocked inside the host's open transaction: the host commits its own rows, and the dropped log row
    is logged;
  - an admin purge blocked by a row lock: `CONFLICT_002` after a deleted batch, `CONFLICT_001` + `Retry-After`
    otherwise, and the purge completes once the lock is released; with `commit_tracking` off (the default) and `bundle`.
- `tests/Integration/Concurrency/PurgeBatchConflictTest.php` (SQLite): the entry purge, the rollup purge and the admin
  answer when a later purge batch meets a lock conflict.
- The DBAL 3 and DBAL 4 legs of "Real databases" also run the whole Integration suite on MySQL 8.4 + PostgreSQL 16 and
  on MariaDB 11.4 + PostgreSQL 16.
- `tests/Integration/OpenHostTransactionTest.php` (SQLite always, plus PostgreSQL, MySQL and MariaDB in CI): a failed
  insert never aborts the host's transaction, a host savepoint with a similar name survives, and a lost savepoint marks
  the host's transaction rollback-only.
- `tests/Unit/Doctrine/LogWriteUnitTest.php`: auto-commit, own transaction, replays (deadlock and serialization only),
  savepoints, and the lost savepoint.
- `tests/Unit/EventSubscriber/HttpLogConflictListenerTest.php`: 409 / 503, the codes and headers for each kind of
  conflict, and that unique violations, lost connections, domain errors and host routes are not mapped.

## Residual risks

Accepted, contention-only (no data loss, leak or permission impact):

- **Sync capture under contention drops the log row.** With `async: false`, a lock conflict on a log or usage write
  is logged and the row (or the count) is lost, so that the host request is never broken or answered with an error
  for its log. The rollup's own transaction replays a deadlock twice first. Use `async: true` with a retrying
  transport where every row matters.
- **A rollup count inside the host's transaction holds the bucket's row lock until the host commits.** That happens
  only when the host keeps a transaction open across 100 buffered usage captures, or until `kernel.terminate`. Other
  workers counting the same bucket wait for it.
- **After a lost savepoint, the host's statements until its `commit()` may already be committed.** MySQL and MariaDB
  run them in auto-commit once the server has ended the transaction. The bundle marks the transaction rollback-only,
  so the host's `commit()` fails and the request reports an error, but those statements are not undone. This is
  server behaviour for any deadlock victim. Before 1.22 the host's `commit()` succeeded silently.
- **Erasure racing a capture.** A request of the erased person that is captured while the erasure runs, after its
  batch was read, is stored again. The request that erased the data itself is never written again (LY-L2).
- **Backfill.** Two backfills of the same day at the same time both see "no buckets" and both write them, so the day
  is counted twice. Run one backfill at a time (it is an operator command).
