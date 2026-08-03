<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Command;

use Nowo\HttpLogBundle\Command\PurgeHttpLogCommand;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeHttpLogCommandTest extends TestCase
{
    #[Test]
    public function executePurgesAndReportsSuccess(): void
    {
        $purgeService = $this->createMock(PurgeHttpLogService::class);
        $purgeService->expects(self::once())
            ->method('purgeByRetention')
            ->with(14, false)
            ->willReturn(9);

        $tester = new CommandTester(new PurgeHttpLogCommand($purgeService));
        $status = $tester->execute(['--days' => '14']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Purged 9 HTTP log entries.', $tester->getDisplay());
    }

    #[Test]
    public function executeDryRunReportsWouldPurgeCount(): void
    {
        $purgeService = $this->createMock(PurgeHttpLogService::class);
        $purgeService->expects(self::once())
            ->method('purgeByRetention')
            ->with(null, true)
            ->willReturn(4);

        $tester = new CommandTester(new PurgeHttpLogCommand($purgeService));
        $status = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Would purge 4 HTTP log entries.', $tester->getDisplay());
    }
}
