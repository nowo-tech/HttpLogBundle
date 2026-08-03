<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Command\ExportHttpLogCommand;
use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ExportHttpLogCommandTest extends TestCase
{
    #[Test]
    public function executeFailsWhenOutputMissing(): void
    {
        $command = new ExportHttpLogCommand(
            $this->createMock(ExportHttpLogService::class),
            $this->createMock(ClockInterface::class),
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('--output option is required', $tester->getDisplay());
    }

    #[Test]
    public function executeExportsToFileWithCriteria(): void
    {
        $now   = new DateTimeImmutable('2026-08-03T12:00:00+00:00');
        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn($now);

        $exportService = $this->createMock(ExportHttpLogService::class);
        $exportService->expects(self::once())
            ->method('exportToFile')
            ->with(
                self::callback(static fn (array $criteria): bool => isset($criteria['createdFrom'])
                    && $criteria['createdFrom'] instanceof DateTimeImmutable
                    && $criteria['createdFrom']->format('Y-m-d') === $now->modify('-7 days')->format('Y-m-d')),
                ExportFormat::Json,
                '/tmp/http-log-export.json',
            )
            ->willReturn(12);

        $command = new ExportHttpLogCommand(
            $exportService,
            $clock,
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--output' => '/tmp/http-log-export.json',
            '--format' => 'json',
            '--days'   => '7',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('Exported 12 HTTP log entries', $tester->getDisplay());
    }

    #[Test]
    public function executeDefaultsToCsvForUnknownFormat(): void
    {
        $exportService = $this->createMock(ExportHttpLogService::class);
        $exportService->expects(self::once())
            ->method('exportToFile')
            ->with([], ExportFormat::Csv, '/tmp/out.csv')
            ->willReturn(1);

        $command = new ExportHttpLogCommand(
            $exportService,
            $this->createMock(ClockInterface::class),
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--output' => '/tmp/out.csv',
            '--format' => 'unknown',
        ]);

        self::assertSame(Command::SUCCESS, $status);
    }
}
