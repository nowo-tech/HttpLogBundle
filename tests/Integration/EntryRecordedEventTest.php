<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\HttpLogBundle\Event\HttpLogEntryRecordedEvent;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use Nowo\HttpLogBundle\Tests\Support\CollectingLogger;
use Nowo\HttpLogBundle\Tests\Support\RecordedEventSpy;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_map;
use function count;

/**
 * REQ-ARCH-001 rules 4 and 7: `HttpLogEntryRecordedEvent` carries ids and scalars only and is dispatched after the
 * row is committed (autocommit insert, or the rollup store's own transaction), never inside a transaction the host
 * left open, never for a row that was not written. A failing listener never undoes the row nor breaks the request.
 * The rows are byte-identical with and without a dispatcher.
 */
final class EntryRecordedEventTest extends TestCase
{
    #[Test]
    public function aWebEntryIsAnnouncedAfterItsAutocommitInsert(): void
    {
        $db       = new UsageTestDatabase();
        $events   = $this->dispatcher($db);
        $recorder = $this->recorder($db, new CollectingLogger(), $events);

        $request = Request::create('/tasks/42?q=1', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->attributes->set('_route', 'task_show');
        $recorder->record($request, new Response('ok', 201), 12.5, 'req-1');
        // same request id: the insert fails, nothing is announced
        $recorder->record($request, new Response('ok', 201), 12.5, 'req-1');

        self::assertCount(1, $db->rows());
        self::assertCount(1, $events->dispatched);
        $event = $events->dispatched[0];
        self::assertSame(HttpLogEntryRecordedEvent::CHANNEL_WEB, $event->channel);
        self::assertTrue($event->isWeb());
        self::assertSame('req-1', $event->requestId);
        self::assertSame('POST', $event->method);
        self::assertSame('task_show', $event->routeName);
        self::assertSame(201, $event->statusCode);
        self::assertSame(12.5, $event->durationMs);
        self::assertEquals(new DateTimeImmutable('2026-11-10T10:00:00+00:00'), $event->recordedAt);
        self::assertNull($event->tenantKey);
        self::assertSame([false], $events->inTransaction, 'committed before the dispatch');
    }

    #[Test]
    public function nothingIsAnnouncedInsideATransactionTheHostLeftOpen(): void
    {
        $db       = new UsageTestDatabase();
        $events   = $this->dispatcher($db);
        $recorder = $this->recorder($db, new CollectingLogger(), $events);
        $writer   = new UsageEntryWriter($db, null, $events);

        $db->connection()->beginTransaction();
        $recorder->record(Request::create('/a'), new Response('ok'), 1.0, 'req-open');
        self::assertTrue($writer->write($this->usage('outbound')));
        $db->connection()->rollBack();

        self::assertSame([], $events->dispatched);
        self::assertCount(0, $db->rows(), 'the host rolled the rows back: announcing them would have lied');
    }

    #[Test]
    public function aFailingListenerIsLoggedAndTheRowStays(): void
    {
        $db      = new UsageTestDatabase();
        $logger  = new CollectingLogger();
        $failing = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                throw new LogicException('listener failure');
            }
        };

        $this->recorder($db, $logger, $failing)->record(Request::create('/a'), new Response('ok'), 1.0, 'req-1');
        self::assertTrue((new UsageEntryWriter($db, null, $failing, $logger))->write($this->usage('outbound')));

        self::assertCount(2, $db->rows());
        $warnings = $logger->warnings();
        self::assertCount(2, $warnings);
        self::assertSame([LogicException::class, LogicException::class], array_map(static fn (array $w): mixed => $w['context']['exception_class'] ?? null, $warnings));
        self::assertCount(2, $logger->records, 'the capture itself did not fail');
    }

    /** MR-H-I3: failing to read the transaction state skips the event and is not reported as a listener failure. */
    #[Test]
    public function aTransactionStateLookupFailureSkipsTheEventWithItsOwnWarning(): void
    {
        $db       = new UsageTestDatabase();
        $calls    = 0;
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(static function () use ($db, &$calls): ObjectManager {
            if (++$calls > 1) {
                throw new RuntimeException('registry failure');
            }

            return $db->entityManager;
        });
        $events = $this->dispatcher($db);
        $logger = new CollectingLogger();

        self::assertTrue((new UsageEntryWriter($registry, null, $events, $logger))->write($this->usage('outbound')));

        self::assertCount(1, $db->rows(), 'the row is written');
        self::assertSame([], $events->dispatched);
        self::assertCount(1, $logger->warnings());
        self::assertSame('HTTP log entry-recorded event skipped: the transaction state could not be read.', $logger->warnings()[0]['message']);
        self::assertSame(RuntimeException::class, $logger->warnings()[0]['context']['exception_class'] ?? null);
    }

    #[Test]
    public function aUsageRowIsAnnouncedWithItsKeysOnlyOnceAndNeverForACountOnlyEvent(): void
    {
        $db     = RollupTestPlatforms::open('sqlite');
        $events = $this->dispatcher($db);
        $store  = new UsageRollupStore($db, $db->rollupTables, new MockClock(new DateTimeImmutable('2026-11-08T12:00:00+00:00')));
        $writer = new UsageEntryWriter($db, $store, $events);

        self::assertTrue($writer->write($this->usage('api', eventId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', rollup: true)));
        self::assertFalse($writer->write($this->usage('api', eventId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', rollup: true)), 'redelivered');
        $writer->write($this->usage('api', eventId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', rollup: true, row: false));
        self::assertTrue($writer->write($this->usage('outbound', eventId: 'cccccccccccccccccccccccccccccccc')));
        self::assertFalse($writer->write($this->usage('outbound', eventId: 'cccccccccccccccccccccccccccccccc')), 'duplicate');

        self::assertSame(2, count($db->rows()));
        self::assertCount(2, $events->dispatched);
        [$api, $outbound] = $events->dispatched;
        self::assertSame(['api', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'GET', 'api_task', 200, 30.0, 'org-a', 'project-1', 'personal_token', 'pt-7', null], [
            $api->channel, $api->requestId, $api->method, $api->routeName, $api->statusCode, $api->durationMs,
            $api->tenantKey, $api->projectKey, $api->credentialKind, $api->credentialId, $api->provider,
        ]);
        self::assertFalse($api->isWeb());
        self::assertSame(['outbound', 'cccccccccccccccccccccccccccccccc'], [$outbound->channel, $outbound->requestId]);
        self::assertSame([false, false], $events->inTransaction, 'after the rollup store committed');
    }

    /** No personal data or request content travels in the event (GDPR: nothing new leaves the log). */
    #[Test]
    public function theEventCarriesIdsAndScalarsOnly(): void
    {
        $names = array_map(static fn (ReflectionProperty $p): string => $p->getName(), (new ReflectionClass(HttpLogEntryRecordedEvent::class))->getProperties());

        self::assertSame(['channel', 'requestId', 'method', 'routeName', 'statusCode', 'durationMs', 'recordedAt', 'tenantKey', 'projectKey', 'credentialKind', 'credentialId', 'provider'], $names);
        self::assertNull((new ReflectionClass(HttpLogEntryRecordedEvent::class))->getParentClass() ?: null, 'no Symfony component base class');
    }

    /** The dispatcher changes nothing in what is stored. */
    #[Test]
    public function theRowsAreTheSameWithAndWithoutADispatcher(): void
    {
        $rows = [];
        foreach ([false, true] as $withEvents) {
            $db      = new UsageTestDatabase();
            $events  = $withEvents ? $this->dispatcher($db) : null;
            $request = Request::create('/tasks/42?token=x', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_TRACE' => 't']);
            $this->recorder($db, new CollectingLogger(), $events)->record($request, new Response('{"a":1}', 200, ['Content-Type' => 'application/json']), 3.0, 'req-same');
            (new UsageEntryWriter($db, null, $events))->write($this->usage('outbound', eventId: 'dddddddddddddddddddddddddddddddd'));
            $rows[] = $db->rows();
        }

        self::assertSame($rows[0], $rows[1]);
    }

    private function dispatcher(UsageTestDatabase $db): RecordedEventSpy
    {
        return new RecordedEventSpy($db);
    }

    private function recorder(UsageTestDatabase $db, CollectingLogger $logger, ?EventDispatcherInterface $events): HttpLogRecorder
    {
        $captureConfig = ['request_headers' => true, 'response_headers' => true, 'request_body' => false, 'client_ip' => true, 'user' => false, 'max_body_bytes' => 65536];

        return new HttpLogRecorder(
            $db->entityManager,
            $this->createStub(MessageBusInterface::class),
            new MockClock(new DateTimeImmutable('2026-11-10T10:00:00+00:00')),
            $logger,
            new HttpLogRedactor([], [], []),
            new CapturePolicy($captureConfig),
            new ContentTypeClassifier(),
            false,
            $captureConfig,
            null,
            $db,
            events: $events,
        );
    }

    private function usage(string $channel, ?string $eventId = null, bool $rollup = false, bool $row = true): UsageCapture
    {
        $inbound = $channel === 'api';

        return new UsageCapture(
            channel: $channel,
            createdAt: new DateTimeImmutable('2026-11-08T10:00:00+00:00'),
            method: 'GET',
            path: $inbound ? '/api/tasks/{id}' : '/v2/{x}',
            provider: $inbound ? null : 'clickup',
            statusCode: 200,
            durationMs: 30.0,
            tenantKey: 'org-a',
            projectKey: 'project-1',
            eventId: $eventId,
            routeName: $inbound ? 'api_task' : null,
            clientIp: $inbound ? '203.0.113.0' : null,
            userIdentifier: $inbound ? 'ana@example.test' : null,
            credentialKind: $inbound ? 'personal_token' : null,
            credentialId: $inbound ? 'pt-7' : null,
            row: $row,
            rollup: $rollup,
        );
    }
}
