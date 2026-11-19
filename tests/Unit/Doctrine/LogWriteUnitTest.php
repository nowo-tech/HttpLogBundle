<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\HttpLogBundle\Doctrine\LogWriteUnit;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function array_map;

/**
 * REQ-RUNTIME-003 rules 1 to 3 for the bundle's writes: auto-commit, own transaction (with replays), or a savepoint in
 * the host's transaction that never aborts it.
 */
#[CoversClass(LogWriteUnit::class)]
final class LogWriteUnitTest extends TestCase
{
    public function testASingleWriteOutsideATransactionRunsInAutoCommit(): void
    {
        $connection = $this->connection();

        $active = LogWriteUnit::run($connection, static function (Connection $connection): bool {
            $connection->insert('note', ['id' => 1]);

            return $connection->isTransactionActive();
        });

        self::assertFalse($active);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM note'));
    }

    public function testAUnitOutsideATransactionRunsInItsOwnTransaction(): void
    {
        $connection = $this->connection();

        $level = LogWriteUnit::run($connection, static function (Connection $connection): int {
            $connection->insert('note', ['id' => 1]);
            $connection->insert('note', ['id' => 2]);

            return $connection->getTransactionNestingLevel();
        }, true);

        self::assertSame(1, $level);
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM note'));
    }

    public function testAFailedUnitRollsBackAsAWholeAndANonTransientErrorIsRethrownUnchanged(): void
    {
        $connection = $this->connection();

        try {
            LogWriteUnit::run($connection, static function (Connection $connection): void {
                $connection->insert('note', ['id' => 1]);
                $connection->insert('note', ['id' => 1]);
            }, true);
            self::fail('expected a unique violation');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertFalse($connection->isTransactionActive());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM note'));
    }

    public function testADeadlockOfTheOwnTransactionIsReplayedAndTheUnitStoredOnce(): void
    {
        $connection = $this->connection();
        $calls      = 0;

        LogWriteUnit::run($connection, static function (Connection $connection) use (&$calls): void {
            $connection->insert('note', ['id' => 1]);
            if (++$calls <= LogWriteUnit::REPLAYS) {
                throw new DeadlockException(self::driverError('Deadlock found', '40001', 1213), null);
            }
        }, true);

        self::assertSame(LogWriteUnit::REPLAYS + 1, $calls);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM note'));
    }

    public function testASerializationFailureIsReplayedButNotForever(): void
    {
        $connection = $this->connection();
        $calls      = 0;

        try {
            LogWriteUnit::run($connection, static function () use (&$calls): never {
                ++$calls;

                throw new DriverException(self::driverError('could not serialize access', '40001'), null);
            }, true);
            self::fail('expected a transient failure');
        } catch (TransientStorageFailure $failure) {
            self::assertInstanceOf(DriverException::class, $failure->getPrevious());
        }

        self::assertSame(LogWriteUnit::REPLAYS + 1, $calls);
        self::assertFalse($connection->isTransactionActive());
    }

    public function testALockWaitTimeoutIsNeverReplayed(): void
    {
        $connection = $this->connection();
        $calls      = 0;

        try {
            LogWriteUnit::run($connection, static function () use (&$calls): never {
                ++$calls;

                throw new LockWaitTimeoutException(self::driverError('Lock wait timeout exceeded', 'HY000', 1205), null);
            }, true);
            self::fail('expected a transient failure');
        } catch (TransientStorageFailure) {
        }

        self::assertSame(1, $calls, 'a log write never waits twice');
    }

    public function testInsideTheHostTransactionAFailedWriteRollsBackOnlyItself(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->insert('note', ['id' => 1]);

        try {
            LogWriteUnit::run($connection, static function (Connection $connection): void {
                $connection->insert('note', ['id' => 2]);
                $connection->insert('note', ['id' => 1]);
            }, true);
            self::fail('expected a unique violation');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertSame(1, $connection->getTransactionNestingLevel(), 'no level of the host closed or opened');
        self::assertFalse($connection->isRollbackOnly());
        $connection->insert('note', ['id' => 3]);
        $connection->commit();

        self::assertSame(['1', '3'], array_map(strval(...), $connection->fetchFirstColumn('SELECT id FROM note ORDER BY id')));
    }

    public function testInsideTheHostTransactionASuccessfulWriteStaysInIt(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        $result = LogWriteUnit::run($connection, static function (Connection $connection): string {
            $connection->insert('note', ['id' => 1]);

            return 'written';
        });
        $connection->rollBack();

        self::assertSame('written', $result);
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM note'), 'the host rolled it back with its own work');
    }

    /**
     * Rule 3: the server dropped the host's transaction (a MySQL deadlock): the savepoint is gone, the host must not
     * commit what follows as one unit.
     */
    public function testALostSavepointMarksTheHostTransactionRollbackOnlyAndRethrowsTheOriginalError(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $deadlock = new DeadlockException(self::driverError('Deadlock found', '40001', 1213), null);

        try {
            LogWriteUnit::run($connection, static function (Connection $connection) use ($deadlock): never {
                $connection->executeStatement('ROLLBACK'); // what the server does on a deadlock

                throw $deadlock;
            });
            self::fail('expected the deadlock');
        } catch (Throwable $failure) {
            self::assertSame($deadlock, $failure);
        }

        self::assertTrue($connection->isRollbackOnly());
        $this->expectException(ConnectionException::class);
        $connection->commit();
    }

    public function testALostSavepointWithNoLevelLeftStillRethrowsTheOriginalError(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('createSavepoint');
        $connection->method('rollbackSavepoint')->willThrowException(new RuntimeException('SAVEPOINT does not exist'));
        $connection->expects(self::once())->method('setRollbackOnly')->willThrowException(new RuntimeException('no active transaction'));
        $connection->expects(self::never())->method('releaseSavepoint');

        $this->expectExceptionMessage('the original');
        LogWriteUnit::run($connection, static function (): never {
            throw new RuntimeException('the original');
        });
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE note (id INT NOT NULL PRIMARY KEY)');

        return $connection;
    }

    private static function driverError(string $message, ?string $sqlState, int $code = 0): AbstractException
    {
        return new class($message, $sqlState, $code) extends AbstractException {
        };
    }
}
