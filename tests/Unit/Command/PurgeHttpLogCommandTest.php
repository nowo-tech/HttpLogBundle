<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Command;

use InvalidArgumentException;
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

    #[Test]
    public function withUsageChannelsEachScopeGetsALine(): void
    {
        $purgeService = $this->createMock(PurgeHttpLogService::class);
        $purgeService->method('scopes')->willReturn(['web', 'outbound', 'mailer']);
        $purgeService->expects(self::once())->method('purgeScopes')->with([], null, false)->willReturn(['web' => 1, 'outbound' => 2, 'mailer' => 0]);

        $tester = new CommandTester(new PurgeHttpLogCommand($purgeService));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('web: purged 1 entries', $display);
        self::assertStringContainsString('outbound: purged 2 entries', $display);
        self::assertStringContainsString('Purged 3 HTTP log entries.', $display);
    }

    #[Test]
    public function channelOptionLimitsTheScopesAndDaysOverridesOnlyThem(): void
    {
        $purgeService = $this->createMock(PurgeHttpLogService::class);
        $purgeService->method('scopes')->willReturn(['web', 'outbound']);
        $purgeService->expects(self::once())->method('purgeScopes')->with(['outbound'], 3, true)->willReturn(['outbound' => 5]);

        $tester = new CommandTester(new PurgeHttpLogCommand($purgeService));
        self::assertSame(Command::SUCCESS, $tester->execute(['--channel' => ['outbound'], '--days' => '3', '--dry-run' => true]));
        self::assertStringContainsString('outbound: would purge 5 entries', $tester->getDisplay());
    }

    #[Test]
    public function bareDaysKeepsThe114MeaningWithUsageChannels(): void
    {
        $purgeService = $this->createMock(PurgeHttpLogService::class);
        $purgeService->method('scopes')->willReturn(['web', 'outbound']);
        $purgeService->expects(self::never())->method('purgeScopes');
        $purgeService->expects(self::once())->method('purgeByRetention')->with(7, false)->willReturn(2);

        $tester = new CommandTester(new PurgeHttpLogCommand($purgeService));
        $tester->execute(['--days' => '7']);
        self::assertStringContainsString('Purged 2 HTTP log entries.', $tester->getDisplay());
    }

    #[Test]
    public function anUnknownChannelIsAnError(): void
    {
        $purgeService = $this->createMock(PurgeHttpLogService::class);
        $purgeService->method('scopes')->willReturn(['web']);
        $purgeService->method('purgeScopes')->willThrowException(new InvalidArgumentException('Unknown purge scope "outbound"; expected one of: web.'));

        $tester = new CommandTester(new PurgeHttpLogCommand($purgeService));
        self::assertSame(Command::INVALID, $tester->execute(['--channel' => ['outbound']]));
        self::assertStringContainsString('Unknown purge scope', $tester->getDisplay());
    }
}
