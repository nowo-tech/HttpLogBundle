<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use Nowo\HttpLogBundle\Tests\Support\CollectingLogger;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\TransactionLostOnInsertMiddleware;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_map;
use function dirname;

use const PHP_VERSION_ID;

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

    /**
     * KY-D-I1: the bundle's savepoint name is unique per call. On MySQL / MariaDB a SAVEPOINT with an existing name
     * replaces it, so a fixed name would have destroyed a host savepoint of the same name.
     */
    #[Test]
    #[DataProviderExternal(RollupTestPlatforms::class, 'platforms')]
    public function aHostSavepointWithTheBundlesFormerNameSurvivesACapture(string $source): void
    {
        $db         = RollupTestPlatforms::open($source);
        $connection = $db->connection();
        $connection->executeStatement('DROP TABLE IF EXISTS host_w0_note');
        $connection->executeStatement('CREATE TABLE host_w0_note (id INT NOT NULL PRIMARY KEY, body VARCHAR(40) NOT NULL)');
        $recorder = $this->recorder($db, new CollectingLogger());

        $connection->beginTransaction();
        $connection->insert('host_w0_note', ['id' => 1, 'body' => 'kept']);
        $connection->createSavepoint('nowo_http_log_insert');
        $connection->insert('host_w0_note', ['id' => 2, 'body' => 'rolled back by the host']);
        $recorder->record(Request::create('/a'), new Response('ok'), 1.0, 'req-1');
        $recorder->record(Request::create('/b'), new Response('ok'), 1.0, 'req-2');
        $connection->rollbackSavepoint('nowo_http_log_insert');
        $connection->commit();

        self::assertSame([1], array_map(intval(...), $connection->fetchFirstColumn('SELECT id FROM host_w0_note')));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $db->table()), 'the captures were after the host savepoint');

        $connection->executeStatement('DROP TABLE host_w0_note');
    }

    /**
     * KY-D-I2: when the savepoint is already gone (a MySQL deadlock or lock wait timeout rolls back the whole
     * transaction), the original error is reported, not the failed ROLLBACK TO SAVEPOINT.
     */
    #[Test]
    public function theOriginalErrorIsReportedWhenTheSavepointIsGone(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $configuration->setMiddlewares([new TransactionLostOnInsertMiddleware()]);
        $entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration);
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(HttpLogEntry::class)]);
        $connection = $entityManager->getConnection();

        $logger        = new CollectingLogger();
        $captureConfig = ['request_headers' => false, 'response_headers' => false, 'request_body' => false, 'client_ip' => false, 'user' => false, 'max_body_bytes' => 1024];
        $recorder      = new HttpLogRecorder(
            $entityManager,
            $this->createMock(MessageBusInterface::class),
            new MockClock(new DateTimeImmutable('2026-11-10T10:00:00+00:00')),
            $logger,
            new HttpLogRedactor([], [], []),
            new CapturePolicy($captureConfig),
            new ContentTypeClassifier(),
            false,
            $captureConfig,
        );

        $connection->beginTransaction();
        $recorder->record(Request::create('/a'), new Response('ok'), 1.0, 'req-lost');

        self::assertCount(1, $logger->records);
        $exception = $logger->records[0]['context']['exception'] ?? null;
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame(TransactionLostOnInsertMiddleware::MESSAGE, $exception->getMessage());

        try {
            $connection->rollBack(); // the server already ended the transaction
        } catch (DbalException) {
        }
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
