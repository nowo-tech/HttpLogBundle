<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use Nowo\HttpLogBundle\Tests\Support\CollectingLogger;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A capture inside a transaction the host left open (a sync-handled message on a bus with `doctrine_transaction`, or
 * a host that did not commit before terminate) runs in a savepoint: a failed insert must not abort the host's
 * transaction (PostgreSQL SQLSTATE 25P02). Runs on SQLite always, on MySQL / PostgreSQL when HTTPLOG_TEST_MYSQL_URL /
 * HTTPLOG_TEST_PGSQL_URL are set.
 */
final class OpenHostTransactionTest extends TestCase
{
    #[Test]
    #[DataProviderExternal(RollupTestPlatforms::class, 'platforms')]
    public function aFailedInsertDoesNotAbortTheHostsTransaction(string $source): void
    {
        $db         = RollupTestPlatforms::open($source);
        $connection = $db->connection();
        $connection->executeStatement('DROP TABLE IF EXISTS host_w0_note');
        $connection->executeStatement('CREATE TABLE host_w0_note (id INT NOT NULL PRIMARY KEY, body VARCHAR(40) NOT NULL)');

        $logger   = new CollectingLogger();
        $recorder = $this->recorder($db, $logger);

        $connection->beginTransaction();
        $connection->insert('host_w0_note', ['id' => 1, 'body' => 'before the capture']);
        $recorder->record(Request::create('/a'), new Response('ok'), 1.0, 'req-dup');
        // same request id: the unique index rejects the second insert
        $recorder->record(Request::create('/a'), new Response('ok'), 1.0, 'req-dup');
        $connection->insert('host_w0_note', ['id' => 2, 'body' => 'after the failed capture']);
        $connection->commit();

        self::assertFalse($connection->isTransactionActive());
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM host_w0_note'));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $db->table()));
        self::assertCount(1, $logger->records, 'the failed capture is logged, not thrown');

        $connection->executeStatement('DROP TABLE host_w0_note');
    }

    #[Test]
    public function aFailedInsertOutsideATransactionIsRethrownWithoutOpeningOne(): void
    {
        $db       = RollupTestPlatforms::open('sqlite');
        $recorder = $this->recorder($db, new CollectingLogger());
        $recorder->record(Request::create('/a'), new Response('ok'), 1.0, 'req-dup');
        $recorder->record(Request::create('/a'), new Response('ok'), 1.0, 'req-dup');

        self::assertFalse($db->connection()->isTransactionActive());
        self::assertSame(1, (int) $db->connection()->fetchOne('SELECT COUNT(*) FROM ' . $db->table()));
        self::assertInstanceOf(Connection::class, $db->connection());
    }

    private function recorder(UsageTestDatabase $db, CollectingLogger $logger): HttpLogRecorder
    {
        $captureConfig = ['request_headers' => true, 'response_headers' => true, 'request_body' => false, 'client_ip' => true, 'user' => false, 'max_body_bytes' => 65536];

        return new HttpLogRecorder(
            $db->entityManager,
            $this->createMock(MessageBusInterface::class),
            new MockClock(new DateTimeImmutable('2026-11-10T10:00:00+00:00')),
            $logger,
            new HttpLogRedactor([], [], []),
            new CapturePolicy($captureConfig),
            new ContentTypeClassifier(),
            false,
            $captureConfig,
            null,
            $db,
        );
    }
}
