<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\HttpLogBundle\Doctrine\PurgeConflict;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * 1.23 (audit UV-2, REQ-RUNTIME-003 rule 6): a purge that already deleted a batch turns its lock conflict into a
 * committed ConcurrencyConflict (CONFLICT_002); anything else is left alone.
 */
#[CoversClass(PurgeConflict::class)]
final class PurgeConflictTest extends TestCase
{
    /** @return iterable<string, array{0: Throwable, 1: TransientFailureKind}> */
    public static function lockConflicts(): iterable
    {
        yield 'deadlock' => [new DeadlockException(self::driverError('Deadlock found', '40001', 1213), null), TransientFailureKind::Deadlock];
        yield 'lock wait timeout' => [new LockWaitTimeoutException(self::driverError('Lock wait timeout exceeded', 'HY000', 1205), null), TransientFailureKind::LockWaitTimeout];
        yield 'transient failure of a runner' => [new TransientStorageFailure(3, 'busy'), TransientFailureKind::Retryable];
        yield 'uncommitted conflict of a unit' => [new ConcurrencyConflict(2, TransientFailureKind::Deadlock, 1, new DeadlockException(self::driverError('Deadlock found', '40001', 1213), null)), TransientFailureKind::Deadlock];
    }

    #[Test]
    #[DataProvider('lockConflicts')]
    public function aLockConflictAfterADeletedBatchIsACommittedConflict(Throwable $failure, TransientFailureKind $kind): void
    {
        $conflict = PurgeConflict::after($failure, 3);

        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertTrue($conflict->committed);
        self::assertSame($kind, $conflict->kind);
        self::assertSame(1, $conflict->attempts);
        self::assertNull($conflict->retryAfterSeconds(), 'CONFLICT_002 has no Retry-After');
        self::assertSame($failure, $conflict->getPrevious());
        self::assertSame(ConflictCode::Committed, ConflictCode::forFailure($conflict));
    }

    #[Test]
    #[DataProvider('lockConflicts')]
    public function aLockConflictBeforeAnyDeletionIsRethrownUnchanged(Throwable $failure): void
    {
        self::assertSame($failure, PurgeConflict::after($failure, 0));
    }

    /** @return iterable<string, array{0: Throwable}> */
    public static function notConflicts(): iterable
    {
        yield 'unique violation' => [new UniqueConstraintViolationException(self::driverError('Duplicate entry', '23000', 1062), null)];
        yield 'lost connection' => [new ConnectionLost(self::driverError('MySQL server has gone away', 'HY000', 2006), null)];
        yield 'any other error' => [new RuntimeException('boom')];
    }

    #[Test]
    #[DataProvider('notConflicts')]
    public function anythingElseIsNeverAConflict(Throwable $failure): void
    {
        self::assertSame($failure, PurgeConflict::after($failure, 5));
    }

    #[Test]
    public function anAlreadyCommittedConflictIsNotWrappedTwice(): void
    {
        $committed = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, new DeadlockException(self::driverError('Deadlock found', '40001', 1213), null), committed: true);
        $outer     = new RuntimeException('wrapper', 0, $committed);

        self::assertSame($committed, PurgeConflict::after($committed, 4));
        self::assertSame($outer, PurgeConflict::after($outer, 4));
    }

    private static function driverError(string $message, ?string $sqlState, int $code = 0): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }
}
