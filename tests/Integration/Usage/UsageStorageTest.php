<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Usage;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\MessageHandler\PersistHttpLogHandler;
use Nowo\HttpLogBundle\Model\HttpLogCapture;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageCaptureSink;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_column;
use function serialize;

/**
 * HL-1 S1 storage: the DBAL writer, the async handler branch, the per-channel filters and purge on a real (SQLite)
 * table with the usage columns mapped.
 */
final class UsageStorageTest extends TestCase
{
    private UsageTestDatabase $db;

    protected function setUp(): void
    {
        $this->db = new UsageTestDatabase();
    }

    #[Test]
    public function writerInsertsTheAllowlistedRowWithoutTouchingTheUnitOfWork(): void
    {
        $writer = new UsageEntryWriter($this->db);
        $host   = new HttpLogEntry();
        $this->db->entityManager->persist($host); // a pending host entity must not be flushed by the log write

        self::assertTrue($writer->write($this->capture('outbound', eventId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')));
        self::assertFalse($writer->write($this->capture('outbound', eventId: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')), 'same event id: deduplicated');

        $rows = $this->db->rows();
        self::assertCount(1, $rows);
        self::assertSame('outbound', $rows[0]['channel']);
        self::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $rows[0]['request_id']);
        self::assertSame('org-1', $rows[0]['tenant_key']);
        self::assertSame('project-1', $rows[0]['project_key']);
        foreach (['query_params', 'request_headers', 'response_headers', 'request_body', 'response_body', 'body_content_type', 'client_ip', 'user_identifier', 'route_name', 'credential_kind', 'credential_id'] as $column) {
            self::assertNull($rows[0][$column], $column);
        }
        self::assertTrue($this->db->entityManager->getUnitOfWork()->isScheduledForInsert($host));
    }

    #[Test]
    public function entityGettersReadTheUsageColumns(): void
    {
        (new UsageEntryWriter($this->db))->write($this->capture('mailer', externalMessageId: '<id-1@mail.example>'));

        $entry = $this->db->repository()->findFiltered([], 1, 1)['items'][0];
        self::assertSame('mailer', $entry->getChannel());
        self::assertSame('resend', $entry->getProvider());
        self::assertSame('org-1', $entry->getTenantKey());
        self::assertSame('project-1', $entry->getProjectKey());
        self::assertSame(2048, $entry->getResponseBytes());
        self::assertSame(1, $entry->getRetryCount());
        self::assertSame('http_5xx', $entry->getErrorKind());
        self::assertSame('0123456789abcdef0123456789abcdef', $entry->getMessengerMessageId());
        self::assertSame('id-1@mail.example', $entry->getExternalMessageId());
        self::assertNull($entry->getCredentialKind());
        self::assertNull($entry->getCredentialId());
        self::assertNull((new HttpLogEntry())->getResponseBytes());
    }

    #[Test]
    public function writerNeedsTheMappedColumnsAndAManager(): void
    {
        $plain = new UsageTestDatabase(usageColumns: false);
        try {
            (new UsageEntryWriter($plain))->write($this->capture('outbound'));
            self::fail('expected LogicException');
        } catch (LogicException $exception) {
            self::assertStringContainsString('not mapped', $exception->getMessage());
        }

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $this->expectException(LogicException::class);
        (new UsageEntryWriter($registry))->write($this->capture('outbound'));
    }

    #[Test]
    public function asyncPayloadIsWrittenOnceByTheHandlerAndWebPayloadsKeepTheOrmPath(): void
    {
        $recorder = $this->createMock(HttpLogRecorder::class);
        $recorder->expects(self::once())->method('persistCapture')->with(self::isInstanceOf(HttpLogCapture::class));
        $handler = new PersistHttpLogHandler($this->db->repository(), $recorder, new NullLogger(), new UsageEntryWriter($this->db));
        $message = new PersistHttpLogMessage($this->capture('outbound')->toArray());

        $handler($message);
        $handler($message); // redelivery
        self::assertCount(1, $this->db->rows());

        $handler(new PersistHttpLogMessage(['requestId' => 'web-1', 'method' => 'GET', 'path' => '/', 'createdAt' => '2026-11-08T10:00:00+00:00']));
    }

    #[Test]
    public function handlerDropsUsagePayloadsWhenDisabledOrInvalid(): void
    {
        $handler = new PersistHttpLogHandler($this->db->repository(), $this->createMock(HttpLogRecorder::class), new NullLogger());
        $handler(new PersistHttpLogMessage($this->capture('outbound')->toArray()));

        $enabled = new PersistHttpLogHandler($this->db->repository(), $this->createMock(HttpLogRecorder::class), new NullLogger(), new UsageEntryWriter($this->db));
        $enabled(new PersistHttpLogMessage(['channel' => 'outbound']));

        self::assertSame([], $this->db->rows());
    }

    #[Test]
    public function aHostRollbackDoesNotRollBackTheBufferedLogRow(): void
    {
        // Messenger doctrine transport on the same connection: dispatch = INSERT on that connection.
        $connection = $this->db->connection();
        $connection->executeStatement('CREATE TABLE messenger_messages (body TEXT NOT NULL)');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use ($connection): Envelope {
            $connection->insert('messenger_messages', ['body' => serialize($message)]);

            return new Envelope($message);
        });
        $buffer = new HttpLogBuffer(new UsageCaptureSink(new UsageEntryWriter($this->db), true, $bus));

        $connection->beginTransaction();
        $buffer->add($this->capture('outbound', statusCode: 502)); // the failed third-party call
        $connection->rollBack();                                     // the host handler fails
        $buffer->flush();                                            // WorkerMessageFailedEvent

        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    #[Test]
    public function channelFiltersAndAllowedChannels(): void
    {
        $this->seedChannels();
        $repository = $this->db->repository();

        self::assertSame(1, $repository->countFiltered(['channel' => 'web']));
        self::assertSame(2, $repository->countFiltered(['channel' => 'outbound']));
        self::assertSame(1, $repository->countFiltered(['provider' => 'clickup']));
        self::assertSame(3, $repository->countFiltered(['tenantKey' => 'org-1']));
        self::assertSame(3, $repository->countFiltered(['projectKey' => 'project-1']));
        self::assertSame(0, $repository->countFiltered(['credentialKind' => 'personal_token']));
        self::assertSame(1, $repository->countFiltered(['allowedChannels' => ['web']]));
        self::assertSame(3, $repository->countFiltered(['allowedChannels' => ['web', 'outbound']]));
        self::assertSame(1, $repository->countFiltered(['allowedChannels' => ['mailer']]));
        self::assertSame(0, $repository->countFiltered(['allowedChannels' => []]));
        self::assertTrue($repository->hasUsageColumns());
    }

    #[Test]
    public function withoutTheColumnsUsageFiltersAreIgnoredAndEveryRowIsWeb(): void
    {
        $plain = new UsageTestDatabase(usageColumns: false);
        $plain->entityManager->persist(new HttpLogEntry());
        $plain->entityManager->flush();
        $repository = $plain->repository();

        self::assertFalse($repository->hasUsageColumns());
        self::assertSame(1, $repository->countFiltered(['channel' => 'outbound', 'provider' => 'x']));
        self::assertSame(1, $repository->countFiltered(['allowedChannels' => ['web']]));
        self::assertSame(0, $repository->countFiltered(['allowedChannels' => ['outbound']]));
        self::assertSame(0, $repository->deleteOlderThan(new DateTimeImmutable('+1 day'), 10, 'outbound'));
        self::assertSame(1, $repository->deleteOlderThan(new DateTimeImmutable('+1 day'), 10, 'web'));
    }

    #[Test]
    public function purgeRunsEachScopeWithItsOwnRetention(): void
    {
        $this->seedChannels(new DateTimeImmutable('2026-11-01T10:00:00+00:00'));
        $clock   = new MockClock(new DateTimeImmutable('2026-11-08T10:00:00+00:00'));
        $service = new PurgeHttpLogService($this->db->repository(), $clock, ['days' => 30, 'purge_batch_size' => 1], ['outbound' => 5, 'mailer' => null]);

        self::assertSame(['web', 'outbound', 'mailer'], $service->scopes());
        self::assertSame(['web' => 0, 'outbound' => 2, 'mailer' => 0], $service->purgeScopes([], null, true));
        self::assertSame(2, $service->purgeByRetention());
        self::assertSame(['web', 'mailer'], array_column($this->rowsChannels(), 'channel'));

        // --days with --channel overrides only that scope
        self::assertSame(['web' => 1], $service->purgeScopes(['web'], 3));
        self::assertSame(['mailer'], array_column($this->rowsChannels(), 'channel'));

        // a bare --days keeps the 1.14 meaning: every row of every channel
        self::assertSame(1, $service->purgeByRetention(3));
        self::assertSame([], $this->db->rows());
    }

    #[Test]
    public function purgeScopesRejectsUnknownScopesAndNegativeDays(): void
    {
        $service = new PurgeHttpLogService($this->db->repository(), new MockClock(), ['days' => 30]);

        try {
            $service->purgeScopes(['outbound']);
            self::fail('outbound is not enabled');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('Unknown purge scope "outbound"', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $service->purgeScopes([], -1);
    }

    /** @return list<array{channel: string}> */
    private function rowsChannels(): array
    {
        $out = [];
        foreach ($this->db->rows() as $row) {
            $out[] = ['channel' => (string) ($row['channel'] ?? 'web')];
        }

        return $out;
    }

    private function seedChannels(?DateTimeImmutable $at = null): void
    {
        $web = (new HttpLogEntry())->setCreatedAt($at ?? new DateTimeImmutable());
        $this->db->entityManager->persist($web);
        $this->db->entityManager->flush();
        $this->db->entityManager->clear();

        $writer = new UsageEntryWriter($this->db);
        $writer->write($this->capture('outbound', provider: 'clickup', at: $at));
        $writer->write($this->capture('outbound', provider: 'other', at: $at));
        $writer->write($this->capture('mailer', at: $at));
    }

    private function capture(
        string $channel,
        ?string $eventId = null,
        ?int $statusCode = 200,
        ?string $externalMessageId = null,
        string $provider = 'resend',
        ?DateTimeImmutable $at = null,
    ): UsageCapture {
        return new UsageCapture(
            channel: $channel,
            createdAt: $at ?? new DateTimeImmutable('2026-11-08T10:00:00+00:00'),
            method: $channel === 'mailer' ? 'SEND' : 'GET',
            path: '/v2/{x}',
            provider: $provider,
            scheme: 'https',
            host: 'api.example.com',
            statusCode: $statusCode,
            errorKind: 'http_5xx',
            durationMs: 12.5,
            responseBytes: 2048,
            retryCount: 1,
            contentType: 'application/json',
            tenantKey: 'org-1',
            projectKey: 'project-1',
            messengerMessageId: '0123456789abcdef0123456789abcdef',
            externalMessageId: $externalMessageId,
            eventId: $eventId,
        );
    }
}
