<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

use function count;
use function fclose;
use function fgetcsv;
use function fopen;
use function fwrite;
use function rewind;

use const JSON_THROW_ON_ERROR;

final class ExportHttpLogServiceTest extends TestCase
{
    #[Test]
    public function countForCriteriaDelegatesToRepository(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())
            ->method('countFiltered')
            ->with(['method' => 'GET'])
            ->willReturn(42);

        $service = new ExportHttpLogService($repository);

        self::assertSame(42, $service->countForCriteria(['method' => 'GET']));
    }

    #[Test]
    public function exportToStringCsvContainsHeadersAndRows(): void
    {
        $entries = [$this->createEntry(1, 'req-1'), $this->createEntry(2, 'req-2')];
        $service = new ExportHttpLogService($this->createRepositoryMock($entries));

        $csv = $service->exportToString([], ExportFormat::Csv);

        self::assertStringContainsString('requestId', $csv);
        self::assertStringContainsString('req-1', $csv);
        self::assertStringContainsString('req-2', $csv);
        self::assertStringContainsString('GET', $csv);
    }

    #[Test]
    public function csvCellsThatStartLikeAFormulaAreNeutralised(): void
    {
        $entry = $this->createEntry(1, '=HYPERLINK("http://x")')
            ->setUserIdentifier('@SUM(1+1)')
            ->setRouteName("\tcmd")
            ->setPath('-2+3')
            ->setHost("\rx")
            ->setClientIp('+1');
        $csv = (new ExportHttpLogService($this->createRepositoryMock([$entry, $this->createEntry(2, 'safe')->setUserIdentifier('')])))->exportToString([], ExportFormat::Csv);

        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream)) !== false) {
            $rows[] = $row;
        }
        fclose($stream);
        self::assertSame('\'=HYPERLINK("http://x")', $rows[1][1]);
        self::assertSame("'\rx", $rows[1][4]);
        self::assertSame("'-2+3", $rows[1][5]);
        self::assertSame("'\tcmd", $rows[1][6]);
        self::assertSame("'+1", $rows[1][8]);
        self::assertSame("'@SUM(1+1)", $rows[1][12]);
        self::assertSame('safe', $rows[2][1]);
        self::assertSame('', $rows[2][12]);
        // JSON keeps the raw values
        self::assertStringContainsString('@SUM(1+1)', (new ExportHttpLogService($this->createRepositoryMock([$entry])))->exportToString([], ExportFormat::Json));
    }

    #[Test]
    public function exportToStringJsonIsValidArray(): void
    {
        $entries = [$this->createEntry(1, 'req-json-1'), $this->createEntry(2, 'req-json-2')];
        $service = new ExportHttpLogService($this->createRepositoryMock($entries));

        $json = $service->exportToString([], ExportFormat::Json);

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertCount(2, $decoded);
        self::assertSame('req-json-1', $decoded[0]['requestId']);
        self::assertSame('req-json-2', $decoded[1]['requestId']);
        self::assertStringContainsString('},{', $json);
    }

    #[Test]
    public function exportDetachesEveryLoadedBatchForBothFormats(): void
    {
        $entries    = [$this->createEntry(1, 'req-detach-1'), $this->createEntry(2, 'req-detach-2')];
        $repository = $this->createRepositoryMock($entries);
        $detached   = [];
        $repository->expects(self::exactly(4))
            ->method('detachAll')
            ->willReturnCallback(static function (iterable $batch) use (&$detached): void {
                $detached[] = $batch;
            });

        $service = new ExportHttpLogService($repository);
        $service->exportToString([], ExportFormat::Csv);
        $service->exportToString([], ExportFormat::Json);

        self::assertSame([$entries, [], $entries, []], $detached);
    }

    #[Test]
    public function exportToFileWritesContentToTempFile(): void
    {
        $entries = [$this->createEntry(10, 'req-file')];
        $service = new ExportHttpLogService($this->createRepositoryMock($entries));
        $path    = tempnam(sys_get_temp_dir(), 'http-log-export-');
        self::assertNotFalse($path);

        try {
            $rows = $service->exportToFile([], ExportFormat::Csv, $path);

            self::assertSame(1, $rows);
            self::assertFileExists($path);
            $content = file_get_contents($path);
            self::assertIsString($content);
            self::assertStringContainsString('req-file', $content);
            self::assertSame(0o600, fileperms($path) & 0o777);
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function exportToResourceRejectsNonResource(): void
    {
        $service = new ExportHttpLogService($this->createMock(HttpLogEntryRepository::class));

        $this->expectException(InvalidArgumentException::class);
        $service->exportToResource([], ExportFormat::Csv, 'not-a-stream');
    }

    #[Test]
    public function exportToFileThrowsWhenPathCannotBeOpened(): void
    {
        $service = new ExportHttpLogService($this->createMock(HttpLogEntryRepository::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to open export file');
        set_error_handler(static fn (): true => true);

        try {
            $service->exportToFile([], ExportFormat::Csv, sys_get_temp_dir());
        } finally {
            restore_error_handler();
        }
    }

    #[Test]
    public function exportToStringThrowsWhenTempStreamCannotBeOpened(): void
    {
        $service = new ExportHttpLogService(
            $this->createMock(HttpLogEntryRepository::class),
            static fn (): false => false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to open temporary stream for export.');

        $service->exportToString([], ExportFormat::Csv);
    }

    #[Test]
    public function exportToStringThrowsWhenExportStreamCannotBeRead(): void
    {
        $service = new ExportHttpLogService(
            $this->createRepositoryMock([$this->createEntry(1, 'req-read-fail')]),
            null,
            static fn ($stream): false => false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read export stream.');

        $service->exportToString([], ExportFormat::Csv);
    }

    /** @param list<HttpLogEntry> $entries */
    private function createRepositoryMock(array $entries): HttpLogEntryRepository
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->method('findFiltered')
            ->willReturnCallback(static function (array $criteria, int $page, int $pageSize) use ($entries): array {
                if ($page === 1) {
                    return ['items' => $entries, 'total' => count($entries)];
                }

                return ['items' => [], 'total' => count($entries)];
            });
        $repository->method('countFiltered')->willReturn(count($entries));

        return $repository;
    }

    private function createEntry(int $id, string $requestId): HttpLogEntry
    {
        $entry = (new HttpLogEntry())
            ->setRequestId($requestId)
            ->setMethod('GET')
            ->setScheme('https')
            ->setHost('example.test')
            ->setPath('/api/test')
            ->setRouteName('api_test')
            ->setQueryParams(['page' => '1'])
            ->setStatusCode(200)
            ->setClientIp('127.0.0.1')
            ->setContentType('application/json')
            ->setBodyContentType('json')
            ->setDurationMs(12.5)
            ->setUserIdentifier('user@example.test')
            ->setRequestHeaders(['Accept' => ['application/json']])
            ->setResponseHeaders(['Content-Type' => ['application/json']])
            ->setRequestBody(null)
            ->setResponseBody('{"ok":true}')
            ->setRequestBodyTruncated(false)
            ->setResponseBodyTruncated(false)
            ->setResponseBodyStored(true)
            ->setCreatedAt(new DateTimeImmutable('2026-01-15T10:00:00+00:00'));

        $reflection = new ReflectionProperty(HttpLogEntry::class, 'id');
        $reflection->setValue($entry, $id);

        return $entry;
    }
}
