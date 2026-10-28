<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\MessageHandler;

use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Message\ExportHttpLogMessage;
use Nowo\HttpLogBundle\MessageHandler\ExportHttpLogHandler;
use Nowo\HttpLogBundle\Service\ExportFileStore;
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

    #[Test]
    public function invokeWritesNamedExportsToThePrivateStoreAndPublishesThem(): void
    {
        $dir   = sys_get_temp_dir() . '/http-log-handler-test-' . bin2hex(random_bytes(6));
        $store = new ExportFileStore($dir);
        $name  = $store->reserve(ExportFormat::Json);
        $stale = $dir . '/' . str_repeat('d', 32) . '.csv';
        file_put_contents($stale, 'old');
        touch($stale, time() - 2 * 86400);

        $exportService = $this->createMock(ExportHttpLogService::class);
        $exportService->expects(self::once())
            ->method('exportToFile')
            ->with([], ExportFormat::Json, $dir . '/' . $name . '.part')
            ->willReturnCallback(static function (array $criteria, ExportFormat $format, string $path): int {
                file_put_contents($path, '[]');

                return 0;
            });

        // outputPath from the transport is ignored for named exports.
        (new ExportHttpLogHandler($exportService, $this->createMock(LoggerInterface::class), $store))(
            new ExportHttpLogMessage([], 'json', '/somewhere/else.json', $name),
        );

        self::assertSame($dir . '/' . $name, $store->readyPath($name));
        self::assertFileDoesNotExist($stale);

        unlink($dir . '/' . $name);
        rmdir($dir);
    }
}
