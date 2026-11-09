<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Rollup;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;
use Nowo\HttpLogBundle\Rollup\RollupBucket;
use Nowo\HttpLogBundle\Rollup\RollupSql;
use Nowo\HttpLogBundle\Rollup\RollupTables;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function count;
use function substr_count;

/**
 * HL-1 §9.3 dialects: the upsert of each platform binds every value; other platforms are refused.
 */
final class RollupSqlTest extends TestCase
{
    #[Test]
    public function mysqlAndMariaDbBindTheMergedValuesAgain(): void
    {
        $bucket  = RollupBucket::fromValues(new DateTimeImmutable('2026-11-08T10:00:00+00:00'), 'api', null, null, null, 'org-a', 200, null, null, null, 12.0, true);
        $columns = array_map(strval(...), array_keys($bucket));
        foreach ([new MySQL80Platform(), new MariaDBPlatform()] as $platform) {
            ['sql' => $sql, 'repeat' => $repeat] = RollupSql::upsert($platform, 'nowo_http_log_daily', $columns);
            self::assertStringContainsString('ON DUPLICATE KEY UPDATE calls = calls + ?', $sql);
            self::assertStringContainsString('last_seen_at = GREATEST(last_seen_at, ?)', $sql);
            self::assertStringContainsString('first_seen_at = LEAST(first_seen_at, ?)', $sql);
            self::assertSame(substr_count($sql, '?'), count(RollupSql::parameters($bucket, $repeat)));
        }
    }

    #[Test]
    public function postgresqlAndSqliteUseOnConflict(): void
    {
        $columns = [...RollupTables::KEY_COLUMNS, ...RollupTables::COUNTER_COLUMNS, 'duration_ms_max', 'first_seen_at', 'last_seen_at'];
        $pg      = RollupSql::upsert(new PostgreSQLPlatform(), 'd', $columns);
        self::assertStringContainsString('INSERT INTO d AS b', $pg['sql']);
        self::assertStringContainsString('ON CONFLICT (day, channel, provider, credential_kind, credential_id, tenant_key) DO UPDATE SET calls = b.calls + EXCLUDED.calls', $pg['sql']);
        self::assertStringContainsString('GREATEST(b.duration_ms_max, EXCLUDED.duration_ms_max)', $pg['sql']);
        self::assertSame([], $pg['repeat']);

        $sqlite = RollupSql::upsert(new SQLitePlatform(), 'd', $columns);
        self::assertStringContainsString('DO UPDATE SET calls = calls + excluded.calls', $sqlite['sql']);
        self::assertStringContainsString('MIN(first_seen_at, excluded.first_seen_at)', $sqlite['sql']);
    }

    #[Test]
    public function exactComparisonIsBinaryOnMysqlOnly(): void
    {
        self::assertSame('(tenant_key = :t AND CAST(tenant_key AS BINARY) = CAST(:t AS BINARY))', RollupSql::exact(new MySQL80Platform(), 'tenant_key', ':t'));
        self::assertSame('tenant_key = :t', RollupSql::exact(new PostgreSQLPlatform(), 'tenant_key', ':t'));
    }

    #[Test]
    public function otherPlatformsAreRefused(): void
    {
        $this->expectException(LogicException::class);
        RollupSql::upsert(new OraclePlatform(), 'd', ['day']);
    }
}
