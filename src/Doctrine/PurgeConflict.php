<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Doctrine;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use Throwable;

/**
 * 1.23 (audit UV-2, REQ-RUNTIME-003 rule 6): the failure of a purge that already deleted rows.
 *
 * A purge deletes in batches, each committed on its own (auto-commit). When a batch meets a lock conflict after an
 * earlier batch of the same call was deleted, part of the purge is committed: the failure is rethrown as
 * transaction-doctrine's {@see ConcurrencyConflict} with `committed: true` (CONFLICT_002, no Retry-After), the
 * storage error as previous. A conflict before any deletion, and anything that is not a lock conflict (a unique
 * violation, a lost connection, any other error), is rethrown unchanged.
 *
 * The purge itself then tells CONFLICT_002 from CONFLICT_001, without a commit-tracking middleware on the host's
 * connection. Stateless.
 *
 * @internal
 */
final class PurgeConflict
{
    /**
     * @param int $deleted rows the same purge call deleted (committed) before the failure
     */
    public static function after(Throwable $failure, int $deleted): Throwable
    {
        if ($deleted < 1) {
            return $failure;
        }

        $transient = false;
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof ConcurrencyConflict && $current->committed) {
                return $failure;
            }
            $transient = $transient || $current instanceof TransientStorageFailure;
        }

        $kind = (new TransientFailureClassifier())->classify($failure);
        if (!$kind instanceof TransientFailureKind && !$transient) {
            return $failure;
        }

        return new ConcurrencyConflict(1, $kind ?? TransientFailureKind::Retryable, null, $failure, committed: true);
    }
}
