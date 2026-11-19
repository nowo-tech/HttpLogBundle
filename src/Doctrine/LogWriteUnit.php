<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Doctrine;

use Closure;
use Doctrine\DBAL\Connection;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Throwable;

use function bin2hex;
use function random_bytes;

/**
 * One write of the bundle (a log row, a usage row, a rollup unit) on the connection of the entry's EntityManager
 * (REQ-RUNTIME-003 rules 1 to 3). Stateless; the connection is passed per call.
 *
 * - **Inside a transaction the host left open**, the write runs in a savepoint with a name unique per call (on MySQL /
 *   MariaDB a second SAVEPOINT of the same name replaces the first). A failed write rolls back only itself, so a
 *   plain failed statement never aborts the host's whole transaction (PostgreSQL SQLSTATE 25P02), and DBAL 3 without
 *   nested savepoints never marks the host's transaction rollback-only. Explicit savepoint calls, because DBAL 3
 *   nests transactional() without a savepoint unless the host enabled them. The original error is rethrown.
 * - **When that savepoint is gone** (a MySQL / MariaDB deadlock, or a lock wait timeout with
 *   `innodb_rollback_on_timeout`, rolled back the host's whole transaction), the host's transaction is marked
 *   rollback-only before the original error is rethrown: the host's later commit() then fails instead of reporting
 *   as committed a unit whose first part the server already rolled back (rule 3: a lost savepoint is never success).
 * - **Outside any transaction**, a single statement runs in auto-commit. A unit of several statements
 *   (`$ownTransaction`) runs in its own transaction through transaction-doctrine's connection runner, which repairs
 *   the nesting level after a failure and rethrows a deadlock, lock wait timeout or serialization failure as
 *   {@see TransientStorageFailure}. A deadlock or serialization failure of that own transaction is replayed at most
 *   {@see self::REPLAYS} times: nothing of it was committed and every unit of the bundle is idempotent (unique request
 *   id, unique event-id ledger). A lock wait timeout is never replayed, so a log write never waits twice.
 */
final readonly class LogWriteUnit
{
    /** Replays of an own transaction after a deadlock or serialization failure. */
    public const REPLAYS = 2;

    /** Prefix of the bundle's savepoint names. */
    public const SAVEPOINT_PREFIX = 'nowo_http_log_';

    /**
     * @template T
     *
     * @param Closure(Connection): T $write
     * @param bool $ownTransaction true: several statements, run in one transaction when the host has none open
     *
     * @return T
     */
    public static function run(Connection $connection, Closure $write, bool $ownTransaction = false): mixed
    {
        if ($connection->isTransactionActive()) {
            return self::inSavepoint($connection, $write);
        }

        if (!$ownTransaction) {
            return $write($connection);
        }

        $runner     = DoctrineTransactionRunner::forConnection($connection);
        $classifier = new TransientFailureClassifier();
        for ($replay = 0;; ++$replay) {
            try {
                return $runner->run(static fn (): mixed => $write($connection));
            } catch (TransientStorageFailure $failure) {
                $kind = $classifier->classify($failure);
                if ($replay >= self::REPLAYS || ($kind !== TransientFailureKind::Deadlock && $kind !== TransientFailureKind::SerializationFailure)) {
                    throw $failure;
                }
            }
        }
    }

    /**
     * @template T
     *
     * @param Closure(Connection): T $write
     *
     * @return T
     */
    private static function inSavepoint(Connection $connection, Closure $write): mixed
    {
        $savepoint = self::SAVEPOINT_PREFIX . bin2hex(random_bytes(6));
        $connection->createSavepoint($savepoint);

        try {
            $result = $write($connection);
        } catch (Throwable $exception) {
            try {
                $connection->rollbackSavepoint($savepoint);
            } catch (Throwable) {
                self::hostTransactionLost($connection);
            }

            throw $exception;
        }

        $connection->releaseSavepoint($savepoint);

        return $result;
    }

    /**
     * The server ended the host's transaction (the savepoint went with it): the host's commit() must fail.
     */
    private static function hostTransactionLost(Connection $connection): void
    {
        try {
            $connection->setRollbackOnly();
        } catch (Throwable) {
            // no transaction level left to mark: the host's own commit() fails on its own
        }
    }
}
