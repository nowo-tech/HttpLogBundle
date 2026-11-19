<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Concurrency;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogConflictListener;
use Nowo\HttpLogBundle\Rollup\RollupBucket;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Nowo\HttpLogBundle\Tests\Support\LockedDeleteMiddleware;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use const PHP_INT_MAX;

/**
 * 1.23 (audit UV-2, REQ-RUNTIME-003 rule 6) on SQLite: a purge batch that meets a lock conflict after an earlier batch
 * of the same call was deleted fails as a committed ConcurrencyConflict, so the admin purge answers CONFLICT_002
 * without any commit-tracking middleware (the default since 1.23); before any deletion it stays CONFLICT_001.
 */
final class PurgeBatchConflictTest extends TestCase
{
    private const NOW = '2026-11-19T10:00:00+00:00';

    #[Test]
    public function theRetentionPurgeOfEntriesMarksAConflictAfterADeletedBatchAsCommitted(): void
    {
        [$db, $locks] = $this->database(allowed: 1);
        $this->entries($db, 3);

        $conflict = $this->failure(static fn (): int => $db->repository()->deleteOlderThan(new DateTimeImmutable('2026-06-01'), 1));
        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertTrue($conflict->committed);
        self::assertInstanceOf(LockWaitTimeoutException::class, $conflict->getPrevious());
        self::assertSame(2, $this->rowsIn($db, $db->table()), 'the first batch is deleted');

        $locks->deletes = 0;
        $locks->allowed = 0;
        self::assertInstanceOf(LockWaitTimeoutException::class, $this->failure(static fn (): int => $db->repository()->deleteOlderThan(new DateTimeImmutable('2026-06-01'), 1)), 'nothing deleted: unchanged');

        $locks->allowed = PHP_INT_MAX;
        self::assertSame(2, $db->repository()->deleteOlderThan(new DateTimeImmutable('2026-06-01'), 1));
    }

    #[Test]
    public function theRollupPurgeMarksAConflictAfterADeletedBatchAsCommitted(): void
    {
        [$db, $locks] = $this->database(allowed: 1);
        $store        = new UsageRollupStore($db, $db->rollupTables, new MockClock(new DateTimeImmutable(self::NOW)));
        foreach (['2024-01-01', '2024-01-02', '2024-01-03'] as $day) {
            $store->upsert($db->connection(), RollupBucket::fromCapture(new UsageCapture(channel: 'outbound', createdAt: new DateTimeImmutable($day . 'T10:00:00+00:00'), method: 'GET', provider: 'stripe', statusCode: 200, durationMs: 1.0, row: false, rollup: true)));
        }

        $conflict = $this->failure(static fn (): int => $store->purgeBuckets(batchSize: 1));
        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertTrue($conflict->committed);
        self::assertSame(2, $this->rowsIn($db, $db->rollupTables->daily));

        $locks->deletes = 0;
        $locks->allowed = 0;
        self::assertInstanceOf(LockWaitTimeoutException::class, $this->failure(static fn (): int => $store->purgeBuckets(batchSize: 1)));
    }

    /**
     * Audit VB-3: the next batch's SELECT, not only its DELETE, meeting a lock conflict after a deleted batch is
     * CONFLICT_002 too, for the entry purge and the rollup purge.
     */
    #[Test]
    public function aLockConflictInTheNextBatchSelectAfterADeletedBatchIsCommitted(): void
    {
        [$db, $locks]       = $this->database(allowed: 1);
        $locks->lockSelects = true;
        $this->entries($db, 3);

        $conflict = $this->failure(static fn (): int => $db->repository()->deleteOlderThan(new DateTimeImmutable('2026-06-01'), 1));
        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertTrue($conflict->committed);
        self::assertSame(1, $locks->deletes, 'the second batch failed in its SELECT');
        self::assertSame(2, $this->rowsIn($db, $db->table()));

        $store              = new UsageRollupStore($db, $db->rollupTables, new MockClock(new DateTimeImmutable(self::NOW)));
        $locks->lockSelects = false;
        foreach (['2024-01-01', '2024-01-02'] as $day) {
            $store->upsert($db->connection(), RollupBucket::fromCapture(new UsageCapture(channel: 'outbound', createdAt: new DateTimeImmutable($day . 'T10:00:00+00:00'), method: 'GET', provider: 'stripe', statusCode: 200, durationMs: 1.0, row: false, rollup: true)));
        }
        $locks->deletes     = 0;
        $locks->lockSelects = true;
        $conflict           = $this->failure(static fn (): int => $store->purgeBuckets(batchSize: 1));
        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertTrue($conflict->committed);
        self::assertSame(1, $this->rowsIn($db, $db->rollupTables->daily));

        $locks->deletes = 0;
        $locks->allowed = 0;
        self::assertInstanceOf(LockWaitTimeoutException::class, $this->failure(static fn (): int => $store->purgeBuckets(batchSize: 1)), 'a SELECT before any deletion: unchanged');
    }

    /**
     * End to end through the conflict listener without a commit ledger (commit_tracking off): 409 + CONFLICT_002 once
     * a batch is deleted, 409 + CONFLICT_001 + Retry-After when none was.
     */
    #[Test]
    public function theAdminPurgeAnswersTheConflictCodesWithoutCommitTracking(): void
    {
        [$db, $locks] = $this->database(allowed: 1);
        $this->entries($db, 3);
        $purge    = new PurgeHttpLogService($db->repository(), new MockClock(new DateTimeImmutable(self::NOW)), ['days' => 30, 'purge_batch_size' => 1]);
        $listener = new HttpLogConflictListener();

        $answer = $this->answer($listener, $this->failure(static fn (): int => $purge->purgeByCriteria([])));
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $answer->getHeaders());

        $answer = $this->answer($listener, $this->failure(static fn (): int => $purge->purgeByRetention()));
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders(), 'the next request deleted nothing');

        $locks->deletes = 0;
        $answer         = $this->answer($listener, $this->failure(static fn (): int => $purge->purgeByRetention()));
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $answer->getHeaders(), 'a retention purge that deleted a batch');
        self::assertSame(1, $this->rowsIn($db, $db->table()));
    }

    /**
     * @return array{0: UsageTestDatabase, 1: LockedDeleteMiddleware}
     */
    private function database(int $allowed): array
    {
        $locks = new LockedDeleteMiddleware($allowed);

        return [new UsageTestDatabase(true, '', 'nowo_http_log_', ['driver' => 'pdo_sqlite', 'memory' => true], rollup: true, middlewares: [$locks]), $locks];
    }

    private function entries(UsageTestDatabase $db, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $db->connection()->insert($db->table(), ['method' => 'GET', 'path' => '/old', 'created_at' => '2026-01-01 10:00:00']);
        }
    }

    private function rowsIn(UsageTestDatabase $db, string $table): int
    {
        return (int) $db->connection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function answer(HttpLogConflictListener $listener, Throwable $failure): HttpException
    {
        $request = Request::create('/_http_log/purge', 'POST');
        $request->attributes->set('_controller', HttpLogAdminController::class . '::purge');
        $kernel = $this->createStub(HttpKernelInterface::class);
        $listener->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer);

        return $answer;
    }

    /**
     * @param callable(): mixed $operation
     */
    private function failure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('expected a lock conflict');
    }
}
