<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\MessageHandler;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Message\PurgeHttpLogMessage;
use Nowo\HttpLogBundle\MessageHandler\PurgeHttpLogHandler;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PurgeHttpLogHandlerTest extends TestCase
{
    #[Test]
    public function invokePurgesOlderEntriesWhenBeforeDateIsProvided(): void
    {
        $before  = new DateTimeImmutable('2026-08-03T10:00:00+00:00');
        $service = $this->createMock(PurgeHttpLogService::class);
        $service->expects(self::once())->method('purgeOlderThan')->with($before)->willReturn(2);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('HTTP log purge completed.', ['purged' => 2]);

        $handler = new PurgeHttpLogHandler($service, $logger);
        $handler(new PurgeHttpLogMessage($before));
    }

    #[Test]
    public function invokePurgesByCriteriaWhenCriteriaAreProvided(): void
    {
        $service = $this->createMock(PurgeHttpLogService::class);
        $service->expects(self::once())->method('purgeByCriteria')->with(['method' => 'POST'])->willReturn(4);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('HTTP log purge completed.', ['purged' => 4]);

        $handler = new PurgeHttpLogHandler($service, $logger);
        $handler(new PurgeHttpLogMessage(null, ['method' => 'POST']));
    }

    #[Test]
    public function invokePurgesByRetentionWhenNoArgumentsAreProvided(): void
    {
        $service = $this->createMock(PurgeHttpLogService::class);
        $service->expects(self::once())->method('purgeByRetention')->with()->willReturn(6);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('HTTP log purge completed.', ['purged' => 6]);

        $handler = new PurgeHttpLogHandler($service, $logger);
        $handler(new PurgeHttpLogMessage(null));
    }
}
