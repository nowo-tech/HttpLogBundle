<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\HttpLogBundle\Doctrine\CorrelationColumnsListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Model\HttpLogCapture;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use Nowo\HttpLogBundle\Tests\Support\CollectingLogger;
use Nowo\HttpLogBundle\Tests\Support\RecordedEventSpy;
use Nowo\HttpLogBundle\Tests\Support\RepeatedProviderIdScenario;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_filter;
use function array_key_last;
use function array_keys;
use function array_slice;
use function explode;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * 1.27 `correlation.columns`: `correlation_id` / `upstream_id` are mapped, written, filtered and exported only while the
 * option is on; a host without it keeps its schema and its exports.
 */
final class CorrelationColumnsTest extends TestCase
{
    #[Test]
    public function theListenerMapsBothColumnsAndTheirIndexesOnce(): void
    {
        $db       = new UsageTestDatabase(usageColumns: false, correlation: true);
        $metadata = $db->entityManager->getClassMetadata(HttpLogEntry::class);

        self::assertSame(CorrelationColumnsListener::COLUMN, $metadata->getColumnName(CorrelationColumnsListener::FIELD));
        self::assertSame(CorrelationColumnsListener::UPSTREAM_COLUMN, $metadata->getColumnName(CorrelationColumnsListener::UPSTREAM_FIELD));
        self::assertSame(64, $metadata->getFieldMapping(CorrelationColumnsListener::FIELD)->length);
        self::assertTrue($metadata->getFieldMapping(CorrelationColumnsListener::UPSTREAM_FIELD)->nullable ?? false);

        $indexes = $db->connection()->createSchemaManager()->introspectTable($db->table())->getIndexes();
        $columns = [];
        foreach ($indexes as $index) {
            $columns[$index->getName()] = $index->getColumns();
        }
        self::assertSame(['correlation_id', 'created_at'], $columns[CorrelationColumnsListener::INDEX] ?? null);
        self::assertSame(['upstream_id', 'created_at'], $columns[CorrelationColumnsListener::UPSTREAM_INDEX] ?? null);

        // a second load event of the same class (an already mapped field) is a no-op
        (new CorrelationColumnsListener())->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $db->entityManager));
        self::assertCount(2, array_filter($metadata->getFieldNames(), static fn (string $f): bool => $f === CorrelationColumnsListener::FIELD || $f === CorrelationColumnsListener::UPSTREAM_FIELD));
    }

    #[Test]
    public function webAndUsageRowsShareTheCorrelationIdAndAreFilteredByIt(): void
    {
        $db     = new UsageTestDatabase(correlation: true);
        $events = new RecordedEventSpy($db);
        $this->recorder($db, $events)->persistCapture($this->capture('req-1', 'up-1'));
        $writer = new UsageEntryWriter($db, events: $events);
        $writer->write(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', '/v2', provider: 'clickup', correlationId: 'req-1'));
        $writer->write(new UsageCapture('mailer', new DateTimeImmutable(), 'SEND', '/', correlationId: 'req-1'));
        $writer->write(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', '/v2', provider: 'clickup', correlationId: 'REQ-1'));
        $repository = $db->repository();

        self::assertTrue($repository->hasCorrelationColumns());
        $rows = $db->rows();
        self::assertSame('req-1', $rows[0]['correlation_id'], 'a web row: its request id');
        self::assertSame('req-1', $rows[0]['request_id']);
        self::assertSame('up-1', $rows[0]['upstream_id']);
        self::assertSame('req-1', $rows[1]['correlation_id']);
        self::assertNotSame('req-1', $rows[1]['request_id'], 'usage rows keep their own unique event id');
        self::assertNull($rows[1]['upstream_id']);

        self::assertSame(3, $repository->countFiltered(['correlationId' => 'req-1']), 'exact, case-sensitive');
        self::assertSame(1, $repository->countFiltered(['correlationId' => 'REQ-1']));
        self::assertSame(1, $repository->countFiltered(['upstreamId' => 'up-1']));
        self::assertSame(0, $repository->countFiltered(['upstreamId' => 'up-2']));
        self::assertSame(4, $repository->countFiltered(['correlationId' => '', 'upstreamId' => null]));
        self::assertCount(3, $repository->findListRows(['correlationId' => 'req-1'], 1, 10)['items']);

        $recorded = $events->dispatched;
        self::assertCount(4, $recorded);
        self::assertSame('req-1', $recorded[0]->correlationId);
        self::assertSame('up-1', $recorded[0]->upstreamId);
        self::assertSame('req-1', $recorded[1]->correlationId);
        self::assertNull($recorded[1]->upstreamId);
    }

    #[Test]
    public function withoutTheOptionNothingIsMappedWrittenOrFiltered(): void
    {
        $db = new UsageTestDatabase();
        $this->recorder($db)->persistCapture($this->capture('req-1', 'up-1'));
        (new UsageEntryWriter($db))->write(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', '/v2', correlationId: 'req-1'));
        $repository = $db->repository();

        self::assertFalse($repository->hasCorrelationColumns());
        self::assertArrayNotHasKey('correlation_id', $db->rows()[0]);
        self::assertArrayNotHasKey('upstream_id', $db->rows()[0]);
        self::assertSame(2, $repository->countFiltered(['correlationId' => 'nope', 'upstreamId' => 'nope']), 'filters ignored');

        $json = json_decode((new ExportHttpLogService($repository))->exportToString([], ExportFormat::Json), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json[0]);
        self::assertArrayNotHasKey('correlationId', $json[0]);
    }

    #[Test]
    public function exportsAppendBothColumnsLastAndNeutraliseFormulas(): void
    {
        $db = new UsageTestDatabase(correlation: true);
        $this->recorder($db)->persistCapture($this->capture('-req-1', '@up'));
        $service = new ExportHttpLogService($db->repository());

        $lines = explode("\n", $service->exportToString([], ExportFormat::Csv));
        self::assertStringEndsWith(',errorKind,messengerMessageId,externalMessageId,correlationId,upstreamId', $lines[0]);
        self::assertStringEndsWith(",'-req-1,'@up", $lines[1], 'formula-neutralised like every other CSV cell');

        $json = json_decode($service->exportToString([], ExportFormat::Json), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json[0]);
        self::assertSame(ExportHttpLogService::CORRELATION_FIELDS, array_slice(array_keys($json[0]), -2));
        self::assertSame('-req-1', $json[0]['correlationId'], 'JSON keeps the raw value');
        self::assertSame('upstreamId', array_key_last($json[0]));
    }

    #[Test]
    public function exportsWithoutUsageColumnsAppendTheCorrelationColumns(): void
    {
        $db = new UsageTestDatabase(usageColumns: false, correlation: true);
        $this->recorder($db)->persistCapture($this->capture('req-9', null));
        $service = new ExportHttpLogService($db->repository());

        $lines = explode("\n", $service->exportToString([], ExportFormat::Csv));
        self::assertStringEndsWith(',userIdentifier,createdAt,correlationId,upstreamId', $lines[0]);
        self::assertStringEndsWith(',req-9,', $lines[1]);

        $json = json_decode($service->exportToString([], ExportFormat::Json), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        self::assertIsArray($json[0]);
        self::assertArrayNotHasKey('channel', $json[0]);
        self::assertNull($json[0]['upstreamId']);
    }

    /** ZDW-HL-M1: a repeated provider id never collapses rows (sync and async). */
    #[Test]
    #[DataProvider('modes')]
    public function twoRequestsWithTheSameProviderIdAreTwoRows(bool $async): void
    {
        $rows = RepeatedProviderIdScenario::run(new UsageTestDatabase(usageColumns: false, correlation: true), $async, $this->createStub(HttpKernelInterface::class));

        self::assertCount(2, $rows);
        self::assertNotSame($rows[0]['request_id'], $rows[1]['request_id']);
        self::assertSame(['chain-1', 'chain-1'], [$rows[0]['correlation_id'], $rows[1]['correlation_id']]);
    }

    /** @return iterable<string, array{0: bool}> */
    public static function modes(): iterable
    {
        yield 'sync' => [false];
        yield 'async' => [true];
    }

    private function recorder(UsageTestDatabase $db, ?RecordedEventSpy $events = null): HttpLogRecorder
    {
        $config = ['request_headers' => false, 'response_headers' => false, 'request_body' => false, 'client_ip' => false, 'user' => false, 'max_body_bytes' => 1024];

        return new HttpLogRecorder(
            $db->entityManager,
            $this->createStub(MessageBusInterface::class),
            new MockClock(),
            new CollectingLogger(),
            new HttpLogRedactor([], [], []),
            new CapturePolicy($config),
            new ContentTypeClassifier(),
            false,
            $config,
            managerRegistry: $db,
            events: $events,
        );
    }

    private function capture(string $requestId, ?string $upstreamId): HttpLogCapture
    {
        return new HttpLogCapture(
            requestId: $requestId,
            method: 'GET',
            scheme: 'https',
            host: 'app.example',
            path: '/orders',
            routeName: 'orders',
            queryParams: null,
            statusCode: 200,
            clientIp: null,
            contentType: 'text/html',
            bodyContentType: 'html',
            durationMs: 1.0,
            userIdentifier: null,
            requestHeaders: null,
            responseHeaders: null,
            requestBody: null,
            responseBody: null,
            requestBodyTruncated: false,
            responseBodyTruncated: false,
            responseBodyStored: false,
            createdAt: new DateTimeImmutable('2026-11-28T10:00:00+00:00'),
            upstreamId: $upstreamId,
        );
    }
}
