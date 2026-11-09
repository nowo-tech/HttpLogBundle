<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Usage;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_slice;
use function explode;
use function implode;
use function json_decode;
use function strtok;

use const JSON_THROW_ON_ERROR;

/**
 * HL-1 §11.2 / BC (d): exports append the usage columns only while they are mapped; the 1.14 column set is unchanged.
 */
final class UsageExportTest extends TestCase
{
    private const CSV_1_14 = 'id,requestId,method,scheme,host,path,routeName,statusCode,clientIp,contentType,bodyContentType,durationMs,userIdentifier,createdAt';

    #[Test]
    public function withoutUsageColumnsTheExportIsThatOf114(): void
    {
        $db = new UsageTestDatabase(usageColumns: false);
        $db->entityManager->persist((new HttpLogEntry())->setPath('/a'));
        $db->entityManager->flush();
        $service = new ExportHttpLogService($db->repository());

        self::assertSame(self::CSV_1_14, strtok($service->exportToString([], ExportFormat::Csv), "\n"));
        $json = json_decode($service->exportToString([], ExportFormat::Json), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertArrayNotHasKey('channel', $json[0]);
    }

    #[Test]
    public function withUsageColumnsTheyAreAppended(): void
    {
        $db = new UsageTestDatabase();
        (new UsageEntryWriter($db))->write(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', '/v2', provider: 'clickup', retryCount: 2));
        $service = new ExportHttpLogService($db->repository());

        $lines = explode("\n", $service->exportToString([], ExportFormat::Csv));
        self::assertSame(self::CSV_1_14 . ',' . implode(',', ExportHttpLogService::USAGE_FIELDS), $lines[0]);
        self::assertStringContainsString(',outbound,clickup,', $lines[1]);

        $json = json_decode($service->exportToString([], ExportFormat::Json), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertSame('clickup', $json[0]['provider']);
        self::assertSame(2, $json[0]['retryCount']);
        self::assertSame(ExportHttpLogService::USAGE_FIELDS, array_slice(array_keys($json[0]), -11));
    }
}
