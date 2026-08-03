<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\MessageHandler;

use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Message\ExportHttpLogMessage;
use Nowo\HttpLogBundle\MessageHandler\ExportHttpLogHandler;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ExportHttpLogHandlerTest extends TestCase
{
    #[Test]
    public function invokeFallsBackToCsvAndLogsCompletedExport(): void
    {
        $message = new ExportHttpLogMessage(['method' => 'GET'], 'invalid-format', '/tmp/http-log.csv');

        $exportService = $this->createMock(ExportHttpLogService::class);
        $exportService->expects(self::once())
            ->method('exportToFile')
            ->with(['method' => 'GET'], ExportFormat::Csv, '/tmp/http-log.csv')
            ->willReturn(12);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('HTTP log export completed.', [
                'rows'       => 12,
                'format'     => 'csv',
                'outputPath' => '/tmp/http-log.csv',
            ]);

        $handler = new ExportHttpLogHandler($exportService, $logger);
        $handler($message);
    }
}
