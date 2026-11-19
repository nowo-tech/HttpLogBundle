<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Throwable;

final class PurgeHttpLogServiceTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-08-03T12:00:00+00:00');
    }

    #[Test]
    public function purgeByRetentionWithDaysOverrideCallsDeleteOlderThan(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(static fn (DateTimeImmutable $before): bool => $before->format('Y-m-d H:i:s') === '2026-07-04 12:00:00'))
            ->willReturn(5);

        $service = new PurgeHttpLogService(
            $repository,
            $this->createClock(),
            ['days' => 30],
        );

        self::assertSame(5, $service->purgeByRetention(30));
    }

    #[Test]
    public function purgeByRetentionRejectsNegativeDaysOverride(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::never())->method('deleteOlderThan');

        $service = new PurgeHttpLogService($repository, $this->createClock(), ['days' => 30]);

        $this->expectException(InvalidArgumentException::class);

        $service->purgeByRetention(-5);
    }

    #[Test]
    public function purgeByRetentionDryRunReturnsCountWithoutDelete(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::never())->method('deleteOlderThan');
        $repository->expects(self::once())
            ->method('countFiltered')
            ->willReturn(7);

        $service = new PurgeHttpLogService(
            $repository,
            $this->createClock(),
            ['days' => 7],
        );

        self::assertSame(7, $service->purgeByRetention(7, dryRun: true));
    }

    #[Test]
    public function purgeOlderThanCallsDeleteOlderThan(): void
    {
        $before     = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())
            ->method('deleteOlderThan')
            ->with($before, 1000)
            ->willReturn(3);

        $service = new PurgeHttpLogService(
            $repository,
            $this->createClock(),
            ['days' => 30],
        );

        self::assertSame(3, $service->purgeOlderThan($before));
    }

    #[Test]
    public function purgeByRetentionReturnsZeroWhenRetentionDaysMissing(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::never())->method('deleteOlderThan');
        $repository->expects(self::never())->method('countFiltered');

        $service = new PurgeHttpLogService(
            $repository,
            $this->createClock(),
            [],
        );

        self::assertSame(0, $service->purgeByRetention());
    }

    #[Test]
    public function purgeByRetentionReturnsZeroWhenRetentionDaysNull(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::never())->method('deleteOlderThan');

        $service = new PurgeHttpLogService(
            $repository,
            $this->createClock(),
            ['days' => null],
        );

        self::assertSame(0, $service->purgeByRetention());
    }

    #[Test]
    public function purgeByCriteriaDryRunCountsWithoutDeleting(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())
            ->method('countFiltered')
            ->with(['method' => 'POST'])
            ->willReturn(11);
        $repository->expects(self::never())->method('findFiltered');
        $repository->expects(self::never())->method('deleteByIds');

        $service = new PurgeHttpLogService(
            $repository,
            $this->createClock(),
            ['days' => 30],
        );

        self::assertSame(11, $service->purgeByCriteria(['method' => 'POST'], dryRun: true));
    }

    #[Test]
    public function purgeByCriteriaDeletesIdBatchesUntilRepositoryReturnsNoIds(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::never())->method('findFiltered');
        $repository->expects(self::exactly(2))
            ->method('findIdsFiltered')
            ->with(['method' => 'DELETE'], 1000)
            ->willReturnOnConsecutiveCalls([41], []);
        $repository->expects(self::exactly(2))
            ->method('deleteByIds')
            ->willReturnCallback(static fn (array $ids): int => $ids === [41] ? 1 : 0);

        $service = new PurgeHttpLogService(
            $repository,
            $this->createClock(),
            ['days' => 30],
        );

        self::assertSame(1, $service->purgeByCriteria(['method' => 'DELETE']));
    }

    #[Test]
    public function purgeUsesConfiguredBatchSize(): void
    {
        $before     = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())
            ->method('deleteOlderThan')
            ->with($before, 250)
            ->willReturn(4);
        $repository->expects(self::once())
            ->method('findIdsFiltered')
            ->with(['method' => 'GET'], 250)
            ->willReturn([]);
        $repository->method('deleteByIds')->willReturn(0);

        $service = new PurgeHttpLogService($repository, $this->createClock(), ['days' => 30, 'purge_batch_size' => 250]);

        self::assertSame(4, $service->purgeOlderThan($before));
        self::assertSame(0, $service->purgeByCriteria(['method' => 'GET']));
    }

    #[Test]
    public function purgeFallsBackToDefaultBatchSizeForInvalidValue(): void
    {
        $before     = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())
            ->method('deleteOlderThan')
            ->with($before, 1000)
            ->willReturn(0);

        $service = new PurgeHttpLogService($repository, $this->createClock(), ['purge_batch_size' => 0]);

        self::assertSame(0, $service->purgeOlderThan($before));
    }

    /**
     * 1.23 (audit UV-2): a lock conflict after a deleted batch is a committed ConcurrencyConflict (CONFLICT_002); before
     * any deletion, and any other error, it is rethrown unchanged.
     */
    #[Test]
    public function purgeByCriteriaMarksALockConflictAfterADeletedBatchAsCommitted(): void
    {
        $deadlock   = $this->deadlock();
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->method('findIdsFiltered')->willReturnOnConsecutiveCalls([1], [2]);
        $repository->method('deleteByIds')->willReturnCallback(static fn (array $ids): int => $ids === [1] ? 1 : throw $deadlock);

        $service  = new PurgeHttpLogService($repository, $this->createClock(), ['days' => 30, 'purge_batch_size' => 1]);
        $conflict = $this->failure(static fn (): int => $service->purgeByCriteria([]));

        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertTrue($conflict->committed);
        self::assertSame($deadlock, $conflict->getPrevious());

        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->method('findIdsFiltered')->willReturn([1]);
        $repository->method('deleteByIds')->willThrowException($deadlock);
        $service = new PurgeHttpLogService($repository, $this->createClock(), ['days' => 30]);
        self::assertSame($deadlock, $this->failure(static fn (): int => $service->purgeByCriteria([])), 'nothing deleted: unchanged (CONFLICT_001)');
    }

    #[Test]
    public function purgeScopesMarksALockConflictAfterAnEarlierScopeAsCommitted(): void
    {
        $deadlock   = $this->deadlock();
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->method('deleteOlderThan')->willReturnCallback(static fn (DateTimeImmutable $before, int $size, ?string $channel): int => $channel === 'web' ? 2 : throw $deadlock);

        $service  = new PurgeHttpLogService($repository, $this->createClock(), ['days' => 30], ['outbound' => 7]);
        $conflict = $this->failure(static fn (): array => $service->purgeScopes(['web', 'outbound']));
        self::assertInstanceOf(ConcurrencyConflict::class, $conflict);
        self::assertTrue($conflict->committed);

        self::assertSame($deadlock, $this->failure(static fn (): array => $service->purgeScopes(['outbound', 'web'])), 'the failing scope ran first');

        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->method('countFiltered')->willReturnCallback(static fn (array $criteria): int => $criteria['channel'] === 'web' ? 2 : throw $deadlock);
        $service = new PurgeHttpLogService($repository, $this->createClock(), ['days' => 30], ['outbound' => 7]);
        self::assertSame($deadlock, $this->failure(static fn (): array => $service->purgeScopes(['web', 'outbound'], null, true)), 'a dry run deletes nothing');
    }

    #[Test]
    public function anUnknownScopeIsRejectedBeforeAnyScopeIsPurged(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::never())->method('deleteOlderThan');
        $service = new PurgeHttpLogService($repository, $this->createClock(), ['days' => 30]);

        $this->expectException(InvalidArgumentException::class);
        $service->purgeScopes(['web', 'nope']);
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {
        }, null);
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

        self::fail('expected a failure');
    }

    private function createClock(): ClockInterface
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn($this->now);

        return $clock;
    }
}
