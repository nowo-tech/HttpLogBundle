<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

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

    private function createClock(): ClockInterface
    {
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn($this->now);

        return $clock;
    }
}
