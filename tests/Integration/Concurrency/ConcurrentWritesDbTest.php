<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Concurrency;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\HttpLogBundle\Controller\HttpLogAdminController;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogConflictListener;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\MessageHandler\PersistHttpLogHandler;
use Nowo\HttpLogBundle\Model\HttpLogCapture;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Nowo\HttpLogBundle\Tests\Support\CollectingLogger;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * REQ-RUNTIME-003: two connections (two workers) writing the same rows at the same time, on real servers (MySQL 8.4,
 * MariaDB 11.4, PostgreSQL 16: HTTPLOG_TEST_MYSQL_URL, HTTPLOG_TEST_MARIADB_URL, HTTPLOG_TEST_PGSQL_URL). Connection
 * B holds an uncommitted write, connection A races it. Both sessions wait at most 1 s for a lock, so a hang fails fast.
 *
 * Every case asserts the answer, the stored data (no duplicate, no lost count) and that connection A is still usable.
 */
#[Group('database')]
final class ConcurrentWritesDbTest extends TestCase
{
    private const NOW = '2026-11-19T10:00:00+00:00';

    /** @var list<Connection> connections of the current test, rolled back and closed in tearDown() */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $connection) {
            try {
                while ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            } catch (Throwable) {
            }
            $connection->close();
        }
        $this->connections = [];
    }

    /** @return iterable<string, array{0: string}> */
    public static function servers(): iterable
    {
        yield 'mysql 8.4' => ['HTTPLOG_TEST_MYSQL_URL'];
        yield 'mariadb 11.4' => ['HTTPLOG_TEST_MARIADB_URL'];
        yield 'postgresql 16' => ['HTTPLOG_TEST_PGSQL_URL'];
    }

    /**
     * The async capture of one request id, written by two workers (a redelivered message): the second waits for the
     * first, fails with a lock conflict (never a duplicate), and its replay after the first commit is a no-op.
     */
    #[Test]
    #[DataProvider('servers')]
    public function aRequestIdRaceStoresOneRowAndTheReplayIsANoOp(string $source): void
    {
        [$a, $b]  = $this->pair($source);
        $handlerA = new PersistHttpLogHandler($a->repository(), $this->recorder($a), new CollectingLogger());

        $b->connection()->beginTransaction();
        $this->recorder($b)->persistCapture($this->capture('req-race'));

        $failure = $this->failure(fn () => $handlerA(new PersistHttpLogMessage($this->capture('req-race')->toArray())));
        self::assertNotInstanceOf(UniqueConstraintViolationException::class, $failure);
        self::assertTrue((new TransientFailureClassifier())->isTransient($failure), 'a lock wait, rethrown so Messenger retries it: ' . $failure::class);

        $b->connection()->commit();
        $handlerA(new PersistHttpLogMessage($this->capture('req-race')->toArray()));

        self::assertSame(1, $this->rows($a, $a->table()));
        $this->assertUsable($a->connection());

        // the request id is the natural key: a direct second insert is a unique violation, not a conflict
        $this->expectException(UniqueConstraintViolationException::class);
        $this->recorder($a)->persistCapture($this->capture('req-race'));
    }

    /**
     * The rollup ledger (exactly-once counting of an event): the same event counted by two workers at once is
     * counted once.
     */
    #[Test]
    #[DataProvider('servers')]
    public function theSameEventCountedByTwoWorkersIsCountedOnce(string $source): void
    {
        [$a, $b] = $this->pair($source);
        $event   = $this->countOnly('0123456789abcdef0123456789abcdef');

        $b->connection()->beginTransaction();
        self::assertTrue($this->store($b)->record($event, null));

        $failure = $this->failure(fn (): bool => $this->store($a)->record($event, null));
        self::assertInstanceOf(TransientStorageFailure::class, $failure, 'a lock wait of the own transaction, never replayed');

        $b->connection()->commit();
        self::assertFalse($this->store($a)->record($event, null), 'already counted');

        self::assertSame(1, $this->rows($a, $a->rollupTables->eventSeen));
        self::assertSame(1, (int) $a->connection()->fetchOne('SELECT SUM(calls) FROM ' . $a->rollupTables->daily));
        $this->assertUsable($a->connection());
    }

    /**
     * Two events of the same bucket: the second upsert waits for the first, and once it is replayed both are counted
     * (atomic `calls = calls + 1`, no lost update).
     */
    #[Test]
    #[DataProvider('servers')]
    public function twoEventsOfTheSameBucketAreBothCounted(string $source): void
    {
        [$a, $b] = $this->pair($source);
        $first   = $this->countOnly('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $second  = $this->countOnly('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');

        $b->connection()->beginTransaction();
        self::assertTrue($this->store($b)->record($first, null));

        $failure = $this->failure(fn (): bool => $this->store($a)->record($second, null));
        self::assertInstanceOf(TransientStorageFailure::class, $failure);
        self::assertSame(0, (int) $a->connection()->fetchOne('SELECT COUNT(*) FROM ' . $a->rollupTables->eventSeen . ' WHERE event_id = ?', [$second->eventId]), 'the failed unit left no ledger row');

        $b->connection()->commit();
        self::assertTrue($this->store($a)->record($second, null));

        self::assertSame(2, (int) $a->connection()->fetchOne('SELECT SUM(calls) FROM ' . $a->rollupTables->daily));
        self::assertSame(1, $this->rows($a, $a->rollupTables->daily), 'one bucket');
        $this->assertUsable($a->connection());
    }

    /**
     * A sync capture inside a transaction the host left open: the log insert waits for another worker, fails, and
     * rolls back to its savepoint only. The host's transaction goes on and commits its own rows; the failure is logged.
     */
    #[Test]
    #[DataProvider('servers')]
    public function aLogWriteLockWaitNeverBreaksTheHostTransaction(string $source): void
    {
        [$a, $b] = $this->pair($source);
        $host    = $a->connection();
        $host->executeStatement('DROP TABLE IF EXISTS host_cw_note');
        $host->executeStatement('CREATE TABLE host_cw_note (id INT NOT NULL PRIMARY KEY)');

        $b->connection()->beginTransaction();
        $this->recorder($b)->persistCapture($this->capture('req-held'));

        $logger = new CollectingLogger();
        $host->beginTransaction();
        $host->insert('host_cw_note', ['id' => 1]);
        $this->recorder($a, $logger)->record(Request::create('/checkout'), new Response('ok'), 1.0, 'req-held');
        $host->insert('host_cw_note', ['id' => 2]);
        $host->commit();

        self::assertFalse($host->isTransactionActive());
        self::assertSame(2, $this->rows($a, 'host_cw_note'), 'the host committed both rows');
        self::assertCount(1, $logger->records, 'the dropped log row is logged, never thrown into the host');
        $logged = $logger->records[0]['context']['exception'] ?? null;
        self::assertInstanceOf(Throwable::class, $logged);
        self::assertTrue((new TransientFailureClassifier())->isTransient($logged));

        $b->connection()->rollBack();
        self::assertSame(0, $this->rows($a, $a->table()));
        $host->executeStatement('DROP TABLE host_cw_note');
    }

    /**
     * Admin purge (batches of one row) blocked by another worker's row lock: CONFLICT_002 without Retry-After once a
     * batch is deleted, CONFLICT_001 with Retry-After when nothing was; the purge completes once the lock is gone.
     */
    #[Test]
    #[DataProvider('servers')]
    public function aBlockedAdminPurgeAnswersTheConflictCodes(string $source): void
    {
        $ledger  = new CommitLedger();
        [$a, $b] = $this->pair($source, $ledger);
        foreach (['req-1', 'req-2', 'req-3'] as $requestId) {
            $this->recorder($a)->persistCapture($this->capture($requestId));
        }
        $ids = array_map(intval(...), $a->connection()->fetchFirstColumn('SELECT id FROM ' . $a->table() . ' ORDER BY id'));
        self::assertCount(3, $ids);

        $b->connection()->beginTransaction();
        $b->connection()->fetchAllAssociative('SELECT id FROM ' . $b->table() . ' WHERE id = ? FOR UPDATE', [$ids[1]]);

        $listener = new HttpLogConflictListener(null, $ledger);
        $purge    = new PurgeHttpLogService($a->repository(), new MockClock(new DateTimeImmutable(self::NOW)), ['days' => 30, 'purge_batch_size' => 1]);

        $request = $this->adminRequest($listener);
        $answer  = $this->answer($listener, $request, $this->failure(static fn (): int => $purge->purgeByCriteria([])));
        self::assertSame(409, $answer->getStatusCode());
        self::assertSame(['X-Conflict-Code' => ConflictCode::COMMITTED], $answer->getHeaders(), 'the first batch is deleted');
        self::assertSame(2, $this->rows($a, $a->table()));

        $request = $this->adminRequest($listener);
        $answer  = $this->answer($listener, $request, $this->failure(static fn (): int => $purge->purgeByCriteria([])));
        self::assertSame(['X-Conflict-Code' => ConflictCode::RETRY_SAFE, 'Retry-After' => '1'], $answer->getHeaders(), 'nothing deleted by this request');

        $b->connection()->rollBack();
        self::assertSame(2, $purge->purgeByCriteria([]));
        self::assertSame(0, $this->rows($a, $a->table()));
        $this->assertUsable($a->connection());
    }

    /**
     * @return array{0: UsageTestDatabase, 1: UsageTestDatabase} A (racing, with commit tracking when a ledger is given) and B (holding)
     */
    private function pair(string $source, ?CommitLedger $ledger = null): array
    {
        $a = RollupTestPlatforms::open($source, middlewares: $ledger instanceof CommitLedger ? [new CommitTrackingMiddleware($ledger)] : []);
        $b = new UsageTestDatabase(true, '', 'nowo_http_log_', RollupTestPlatforms::params($source), createSchema: false, rollup: true);
        foreach ([$a->connection(), $b->connection()] as $connection) {
            $this->connections[] = $connection;
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $connection->executeStatement("SET lock_timeout = '1s'");

                continue;
            }
            // row locks and metadata locks (a DROP TABLE of the next test never waits for a leftover transaction)
            $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
            $connection->executeStatement('SET SESSION lock_wait_timeout = 5');
        }

        return [$a, $b];
    }

    private function recorder(UsageTestDatabase $db, ?CollectingLogger $logger = null): HttpLogRecorder
    {
        $captureConfig = ['request_headers' => false, 'response_headers' => false, 'request_body' => false, 'client_ip' => false, 'user' => false, 'max_body_bytes' => 1024];

        return new HttpLogRecorder(
            $db->entityManager,
            $this->createStub(MessageBusInterface::class),
            new MockClock(new DateTimeImmutable(self::NOW)),
            $logger ?? new CollectingLogger(),
            new HttpLogRedactor([], [], []),
            new CapturePolicy($captureConfig),
            new ContentTypeClassifier(),
            false,
            $captureConfig,
            null,
            $db,
        );
    }

    private function store(UsageTestDatabase $db): UsageRollupStore
    {
        return new UsageRollupStore($db, $db->rollupTables, new MockClock(new DateTimeImmutable(self::NOW)));
    }

    private function capture(string $requestId): HttpLogCapture
    {
        return new HttpLogCapture(
            requestId: $requestId,
            method: 'POST',
            scheme: 'https',
            host: 'app.example',
            path: '/checkout',
            routeName: 'checkout',
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
            createdAt: new DateTimeImmutable('2026-10-01T10:00:00+00:00'),
        );
    }

    private function countOnly(string $eventId): UsageCapture
    {
        return new UsageCapture(channel: 'outbound', createdAt: new DateTimeImmutable(self::NOW), method: 'GET', provider: 'stripe', statusCode: 200, durationMs: 12.0, eventId: $eventId, row: false, rollup: true);
    }

    private function adminRequest(HttpLogConflictListener $listener): Request
    {
        $request = Request::create('/_http_log/purge', 'POST');
        $request->attributes->set('_controller', HttpLogAdminController::class . '::purge');
        $listener->onRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST));

        return $request;
    }

    private function answer(HttpLogConflictListener $listener, Request $request, Throwable $failure): HttpException
    {
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $failure);
        $listener->onException($event);
        $answer = $event->getThrowable();
        self::assertInstanceOf(HttpException::class, $answer, 'mapped: ' . $failure::class);

        return $answer;
    }

    /**
     * @param callable(): mixed $operation
     */
    private function failure(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $failure) {
            return $failure;
        }

        self::fail('expected a lock conflict');
    }

    private function rows(UsageTestDatabase $db, string $table): int
    {
        return (int) $db->connection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function assertUsable(Connection $connection): void
    {
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
    }
}
