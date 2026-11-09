<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Rollup;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Usage\HttpLogUsageReader;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use Nowo\HttpLogBundle\Usage\UsageQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function fwrite;
use function getenv;
use function hrtime;
use function sprintf;

use const STDERR;

/**
 * HL-1 S5 measurements (SC-004 and §9.6), run only with HTTPLOG_TEST_PERF=1 on MySQL / MariaDB and PostgreSQL: the
 * 13-month daily query of one tenant is one query under 300 ms on 400 000 buckets, and one bucket takes at least
 * 200 events per second through the exactly-once writer (one connection, sequential: a lower bound of the
 * per-bucket row-lock throughput).
 */
final class RollupPerformanceTest extends TestCase
{
    private const BUCKETS = 400000;

    /** @return iterable<string, array{0: string}> */
    public static function platforms(): iterable
    {
        yield 'mysql' => ['HTTPLOG_TEST_MYSQL_URL'];
        yield 'postgresql' => ['HTTPLOG_TEST_PGSQL_URL'];
    }

    #[Test]
    #[DataProvider('platforms')]
    public function thirteenMonthsOfOneTenantIsOneFastQueryAndOneBucketTakesTwoHundredEventsASecond(string $source): void
    {
        if (getenv('HTTPLOG_TEST_PERF') !== '1') {
            self::markTestSkipped('HTTPLOG_TEST_PERF is not 1.');
        }

        $db         = RollupTestPlatforms::open($source);
        $connection = $db->connection();
        $platform   = $connection->getDatabasePlatform();
        $daily      = $db->rollupTables->daily;
        $columns    = 'day, channel, credential_kind, credential_id, tenant_key, calls, errors, status_2xx, d_le_100, duration_ms_sum, duration_ms_max, first_seen_at, last_seen_at';
        // 395 days x 1 013 credentials, 40 tenants (about 10 000 buckets each)
        if ($platform instanceof AbstractMySQLPlatform) {
            $connection->executeStatement($platform instanceof MariaDBPlatform ? 'SET SESSION max_recursive_iterations = 1000000' : 'SET SESSION cte_max_recursion_depth = 1000000');
            $connection->executeStatement(sprintf("INSERT INTO %s (%s) WITH RECURSIVE seq(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM seq WHERE n < %d) SELECT DATE_ADD('2025-11-01', INTERVAL n %% 395 DAY), 'api', 'personal_token', CONCAT('pt-', n DIV 395), CONCAT('org-', (n DIV 395) %% 40), 3, 1, 2, 3, 150, 90, DATE_ADD('2025-11-01', INTERVAL n %% 395 DAY), DATE_ADD('2025-11-01', INTERVAL n %% 395 DAY) FROM seq", $daily, $columns, self::BUCKETS - 1));
        } else {
            $connection->executeStatement(sprintf("INSERT INTO %s (%s) SELECT DATE '2025-11-01' + (n %% 395), 'api', 'personal_token', 'pt-' || (n / 395), 'org-' || ((n / 395) %% 40), 3, 1, 2, 3, 150, 90, DATE '2025-11-01' + (n %% 395), DATE '2025-11-01' + (n %% 395) FROM generate_series(0, %d) AS n", $daily, $columns, self::BUCKETS - 1));
            $connection->executeStatement('ANALYZE ' . $daily);
        }
        self::assertSame(self::BUCKETS, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $daily));

        $clock  = new MockClock(new DateTimeImmutable('2026-11-30T12:00:00+00:00'));
        $store  = new UsageRollupStore($db, $db->rollupTables, $clock);
        $reader = new HttpLogUsageReader($db, $store);
        $query  = UsageQuery::forTenant('org-7')->between(new DateTimeImmutable('2025-11-01'), new DateTimeImmutable('2026-11-30'))->groupBy('day');
        $reader->daily($query); // warm-up
        $start = hrtime(true);
        $days  = $reader->daily($query);
        $ms    = (hrtime(true) - $start) / 1e6;
        self::assertCount(395, $days);
        self::assertSame(26 * 3, $days[0]->calls, '26 credentials of org-7, 3 calls each');

        $writer = new UsageEntryWriter($db, $store);
        $events = 400;
        $start  = hrtime(true);
        for ($i = 0; $i < $events; ++$i) {
            $writer->write(new UsageCapture(channel: 'api', createdAt: new DateTimeImmutable('2026-11-30T10:00:00+00:00'), method: 'GET', statusCode: 200, durationMs: 20.0, tenantKey: 'org-hot', credentialKind: 'personal_token', credentialId: 'pt-hot', row: false, rollup: true));
        }
        $perSecond = $events / ((hrtime(true) - $start) / 1e9);
        fwrite(STDERR, sprintf("\n[%s] 13-month daily query: %.1f ms on %d buckets; one bucket: %.0f events/s\n", $source, $ms, self::BUCKETS, $perSecond));

        self::assertLessThan(300.0, $ms);
        self::assertGreaterThan(200.0, $perSecond);
        self::assertSame($events, (int) $connection->fetchOne('SELECT calls FROM ' . $daily . " WHERE credential_id = 'pt-hot'"));
        $connection->close();
    }
}
