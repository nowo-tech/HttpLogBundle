<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Privacy;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Privacy\HttpLogClientIpWindow;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataEraser;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataEraserInterface;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataErasure;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataExporter;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubject;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\SpyEventDispatcher;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

use function array_column;
use function gc_collect_cycles;
use function json_encode;
use function memory_get_usage;

use const JSON_THROW_ON_ERROR;

/**
 * GDPR ports (1.18): export shape and bounds, client IP windows, erasure result / event / transactions, exact matching
 * on a case- and accent-insensitive MySQL collation, and a kept-alive worker running two users many times. SQLite
 * always; MySQL / PostgreSQL with HTTPLOG_TEST_MYSQL_URL / HTTPLOG_TEST_PGSQL_URL.
 */
final class HttpLogPersonalDataPortsTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function platforms(): iterable
    {
        return RollupTestPlatforms::platforms();
    }

    #[Test]
    #[DataProvider('platforms')]
    public function exportsMetadataOnlyNewestFirstWithTheTotal(string $source): void
    {
        $db = RollupTestPlatforms::open($source);
        $this->seed($db);
        $db->connection()->executeStatement('UPDATE ' . $db->table() . " SET channel = 'web'");

        $export = $this->exporter($db, 2)->export(HttpLogPersonalDataSubject::fromUserIdentifier('ana'));

        self::assertSame(['type' => 'user_identifier', 'value' => 'ana'], $export['subject']);
        self::assertSame(3, $export['total']);
        self::assertSame(2, $export['limit']);
        self::assertTrue($export['truncated']);
        self::assertSame(['2026-03-01T12:00:00+00:00', '2026-02-01T12:00:00+00:00'], array_column($export['entries'], 'created_at'));
        self::assertSame([
            'created_at'  => '2026-03-01T12:00:00+00:00',
            'method'      => 'POST',
            'scheme'      => 'https',
            'host'        => 'app.example.test',
            'path'        => '/account/3',
            'route_name'  => 'account_show',
            'status_code' => 201,
            'duration_ms' => 12.5,
            'client_ip'   => '10.0.0.2',
            'channel'     => 'web',
        ], $export['entries'][0]);
        self::assertSame([
            ['client_ip' => '10.0.0.1', 'first_seen_at' => '2026-01-01T12:00:00+00:00', 'last_seen_at' => '2026-02-01T12:00:00+00:00'],
            ['client_ip' => '10.0.0.2', 'first_seen_at' => '2026-03-01T12:00:00+00:00', 'last_seen_at' => '2026-03-01T12:00:00+00:00'],
        ], $export['client_ip_windows'], 'the windows cover every entry, not only the exported ones');

        $json = json_encode($export, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('REDACTED-TEST', $json, 'no headers, bodies or query parameters');
        self::assertStringNotContainsString('luis', $json, 'no other user');

        $all = $this->exporter($db, 10)->export(HttpLogPersonalDataSubject::fromUserIdentifier('ana'));
        self::assertFalse($all['truncated']);
        self::assertCount(3, $all['entries']);
        self::assertSame(0, $this->exporter($db, 10)->export(HttpLogPersonalDataSubject::fromUserIdentifier('nobody'))['total']);
    }

    #[Test]
    #[DataProvider('platforms')]
    public function clientIpWindowsGiveFirstAndLastSightingPerAddress(string $source): void
    {
        $db = RollupTestPlatforms::open($source);
        $this->seed($db);

        $windows = $this->exporter($db, 10)->clientIpWindows('ana');

        self::assertContainsOnlyInstancesOf(HttpLogClientIpWindow::class, $windows);
        self::assertSame(['10.0.0.1', '10.0.0.2'], array_column($windows, 'ip'));
        self::assertEquals(new DateTimeImmutable('2026-01-01 12:00:00'), $windows[0]->firstSeenAt);
        self::assertEquals(new DateTimeImmutable('2026-02-01 12:00:00'), $windows[0]->lastSeenAt);
        self::assertSame([], $this->exporter($db, 10)->clientIpWindows('nobody'));
        self::assertSame([], $this->exporter($db, 10)->clientIpWindows('noip'), 'entries without an IP are skipped');
    }

    #[Test]
    #[DataProvider('platforms')]
    public function caseAndAccentVariantsAreNeitherExportedNorErased(string $source): void
    {
        $db = RollupTestPlatforms::open($source);
        if ($db->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // a host that kept a folding collation (the bundle maps utf8mb4_0900_bin / utf8mb4_nopad_bin since 1.20.1)
            $db->connection()->executeStatement('ALTER TABLE ' . $db->table() . ' MODIFY user_identifier VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL');
        }
        foreach (['usera', 'UserA', 'jose', 'josé'] as $user) {
            $this->entry($db, $user, '10.9.9.9', '2026-01-01 12:00:00');
        }
        $db->entityManager->flush();
        $db->entityManager->clear();
        if ($db->connection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::assertSame(2, (int) $db->connection()->fetchOne('SELECT COUNT(*) FROM ' . $db->table() . " WHERE user_identifier = 'usera'"), 'the MySQL collation ignores case');
        }

        self::assertSame(1, $this->exporter($db, 10)->export(HttpLogPersonalDataSubject::fromUserIdentifier('usera'))['total']);
        self::assertCount(1, $this->exporter($db, 10)->clientIpWindows('jose'));
        self::assertSame(1, $this->erasure($db)->erase(HttpLogPersonalDataSubject::fromUserIdentifier('jose'))->entries);
        self::assertSame(1, $this->erasure($db)->erase(HttpLogPersonalDataSubject::fromUserIdentifier('usera'))->entries);
        self::assertSame(['UserA', 'josé'], $db->connection()->fetchFirstColumn('SELECT user_identifier FROM ' . $db->table() . ' ORDER BY id'));
    }

    #[Test]
    #[DataProvider('platforms')]
    public function erasureCommitsItsOwnTransactionThenDispatchesAndIsIdempotent(string $source): void
    {
        $db     = RollupTestPlatforms::open($source);
        $events = $this->events($db->connection());
        $this->seed($db);

        $result = $this->erasure($db, $events)->erase(HttpLogPersonalDataSubject::fromUserIdentifier('ana'));

        self::assertSame(3, $result->entries);
        self::assertSame(['entries' => 3], $result->toArray());
        self::assertCount(1, $events->dispatched);
        self::assertSame('user_identifier', $events->dispatched[0]->subjectType);
        self::assertSame('ana', $events->dispatched[0]->subjectValue);
        self::assertSame(['entries' => 3], $events->dispatched[0]->counts);
        self::assertFalse($events->inTransaction[0], 'dispatched after the commit');

        $again = $this->erasure($db, $events)->erase(HttpLogPersonalDataSubject::fromUserIdentifier('ana'));
        self::assertSame(0, $again->total());
        self::assertSame(1, (int) $db->connection()->fetchOne('SELECT COUNT(*) FROM ' . $db->table() . " WHERE user_identifier = 'luis'"));

        self::assertSame(1, $this->erasure($db)->erase(HttpLogPersonalDataSubject::fromClientIp('10.0.0.3'))->entries, 'by client IP');
    }

    #[Test]
    #[DataProvider('platforms')]
    public function insideTheCallersTransactionNothingIsDispatchedAndARollbackKeepsTheRows(string $source): void
    {
        $db         = RollupTestPlatforms::open($source);
        $connection = $db->connection();
        $events     = $this->events($connection);
        $this->seed($db);

        $connection->beginTransaction();
        self::assertSame(3, $this->erasure($db, $events)->erase(HttpLogPersonalDataSubject::fromUserIdentifier('ana'))->entries);
        self::assertTrue($connection->isTransactionActive(), 'the caller still owns its transaction');
        $connection->rollBack();

        self::assertSame([], $events->dispatched);
        self::assertSame(3, $this->exporter($db, 10)->export(HttpLogPersonalDataSubject::fromUserIdentifier('ana'))['total']);

        $connection->beginTransaction();
        $this->erasure($db, $events)->erase(HttpLogPersonalDataSubject::fromUserIdentifier('ana'));
        $connection->commit();
        self::assertSame([], $events->dispatched, 'not even after the caller commits');
        self::assertSame(0, $this->exporter($db, 10)->export(HttpLogPersonalDataSubject::fromUserIdentifier('ana'))['total']);
    }

    #[Test]
    public function aFailedDeleteRollsBackTheOwnTransactionAndDispatchesNothing(): void
    {
        $db     = new UsageTestDatabase(false);
        $events = $this->events($db->connection());
        $this->seed($db);
        $eraser = new class implements HttpLogPersonalDataEraserInterface {
            public function countByUserIdentifier(string $userIdentifier): int
            {
                return 0;
            }

            public function deleteByUserIdentifier(string $userIdentifier): int
            {
                throw new RuntimeException('database gone');
            }

            public function countByClientIp(string $clientIp): int
            {
                return 0;
            }

            public function deleteByClientIp(string $clientIp): int
            {
                return 0;
            }
        };

        try {
            (new HttpLogPersonalDataErasure($eraser, $db->repository(), $events))->erase(HttpLogPersonalDataSubject::fromUserIdentifier('ana'));
            self::fail('The failure must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame('database gone', $exception->getMessage());
        }
        self::assertSame([], $events->dispatched);
        self::assertFalse($db->connection()->isTransactionActive());
    }

    #[Test]
    public function withoutTheUsageColumnsTheChannelIsNull(): void
    {
        $db = new UsageTestDatabase(false);
        $this->seed($db);

        $export = $this->exporter($db, 1)->export(HttpLogPersonalDataSubject::fromClientIp('10.0.0.1'));

        self::assertSame(3, $export['total'], 'two entries of ana and one of luis');
        self::assertNull($export['entries'][0]['channel']);
        self::assertSame('/account/4', $export['entries'][0]['path'], 'newest first');
    }

    #[Test]
    public function aTinyLimitKeepsTheNewestWhileScanning(): void
    {
        $db = new UsageTestDatabase(false);
        for ($i = 1; $i <= 9; ++$i) {
            $this->entry($db, 'ana', '10.0.0.1', '2026-01-0' . $i . ' 12:00:00');
        }
        $db->entityManager->flush();
        $db->entityManager->clear();

        $export = (new HttpLogPersonalDataExporter($db->repository(), 1, ['purge_batch_size' => 2]))->export(HttpLogPersonalDataSubject::fromUserIdentifier('ana'));

        self::assertSame(9, $export['total']);
        self::assertSame(['2026-01-09T12:00:00+00:00'], array_column($export['entries'], 'created_at'));
        self::assertSame(1000, (new HttpLogPersonalDataExporter($db->repository(), 0, ['purge_batch_size' => 0]))->export(HttpLogPersonalDataSubject::fromUserIdentifier('ana'))['limit'], 'invalid values fall back to the defaults');
    }

    #[Test]
    public function invalidUserIdentifiersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->exporter(new UsageTestDatabase(false), 10)->clientIpWindows('');
    }

    /**
     * FrankenPHP worker mode without kernel reset: the same service instances serve user A then user B many times; no
     * state carries over and memory stays flat.
     */
    #[Test]
    public function aKeptAliveWorkerServesTwoUsersManyTimesWithoutLeaks(): void
    {
        $db = new UsageTestDatabase(false);
        $this->seed($db);
        $exporter = $this->exporter($db, 10);
        $erasure  = $this->erasure($db);
        $ana      = HttpLogPersonalDataSubject::fromUserIdentifier('ana');
        $luis     = HttpLogPersonalDataSubject::fromUserIdentifier('luis');

        $baseline = 0;
        for ($i = 0; $i < 1000; ++$i) {
            $a = $exporter->export($ana);
            $b = $exporter->export($luis);
            self::assertSame(3, $a['total']);
            self::assertSame(1, $b['total']);
            self::assertNotContains('/account/4', array_column($a['entries'], 'path'));
            self::assertSame(['/account/4'], array_column($b['entries'], 'path'));
            self::assertSame(['10.0.0.1'], array_column($exporter->clientIpWindows('luis'), 'ip'));
            self::assertSame(0, $erasure->erase(HttpLogPersonalDataSubject::fromUserIdentifier('nobody'))->entries);
            if ($i === 100) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();
        self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat');

        self::assertSame(3, $erasure->erase($ana)->entries);
        self::assertSame(1, $exporter->export($luis)['total'], 'erasing A leaves B');
    }

    private function seed(UsageTestDatabase $db): void
    {
        $this->entry($db, 'ana', '10.0.0.1', '2026-01-01 12:00:00', '/account/1');
        $this->entry($db, 'ana', '10.0.0.1', '2026-02-01 12:00:00', '/account/2');
        $this->entry($db, 'ana', '10.0.0.2', '2026-03-01 12:00:00', '/account/3');
        $this->entry($db, 'luis', '10.0.0.1', '2026-04-01 12:00:00', '/account/4');
        $this->entry($db, 'noip', null, '2026-04-01 12:00:00', '/account/5');
        $this->entry($db, null, '10.0.0.3', '2026-04-01 12:00:00', '/account/6');
        $db->entityManager->flush();
        $db->entityManager->clear();
    }

    private function entry(UsageTestDatabase $db, ?string $user, ?string $ip, string $at, string $path = '/account/0'): void
    {
        $marker = 'REDACTED-TEST';
        $entry  = (new HttpLogEntry())
            ->setUserIdentifier($user)
            ->setClientIp($ip)
            ->setCreatedAt(new DateTimeImmutable($at))
            ->setMethod('POST')
            ->setScheme('https')
            ->setHost('app.example.test')
            ->setPath($path)
            ->setRouteName('account_show')
            ->setStatusCode(201)
            ->setDurationMs(12.5)
            ->setQueryParams(['token' => $marker])
            ->setRequestHeaders(['authorization' => ['Bearer ' . $marker]])
            ->setResponseHeaders(['set-cookie' => ['sid=' . $marker]])
            ->setRequestBody('{"password":"' . $marker . '"}')
            ->setResponseBody($marker);
        $db->entityManager->persist($entry);
    }

    private function exporter(UsageTestDatabase $db, int $limit): HttpLogPersonalDataExporter
    {
        return new HttpLogPersonalDataExporter($db->repository(), $limit, ['purge_batch_size' => 2]);
    }

    private function erasure(UsageTestDatabase $db, ?EventDispatcherInterface $events = null): HttpLogPersonalDataErasure
    {
        return new HttpLogPersonalDataErasure(new HttpLogPersonalDataEraser($db->repository(), ['purge_batch_size' => 2]), $db->repository(), $events);
    }

    private function events(Connection $connection): SpyEventDispatcher
    {
        return new SpyEventDispatcher($connection);
    }
}
