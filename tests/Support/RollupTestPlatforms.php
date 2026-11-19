<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\SkippedWithMessageException;

use function getenv;
use function is_string;

/**
 * Databases of the rollup tests: SQLite always; MySQL / MariaDB and PostgreSQL when HTTPLOG_TEST_MYSQL_URL /
 * HTTPLOG_TEST_PGSQL_URL point at an empty, throwaway database (its HttpLog tables are dropped first).
 */
final class RollupTestPlatforms
{
    /** @return iterable<string, array{0: string}> */
    public static function platforms(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['HTTPLOG_TEST_MYSQL_URL'];
        yield 'postgresql' => ['HTTPLOG_TEST_PGSQL_URL'];
    }

    /**
     * A fresh database with the usage columns and the rollup tables.
     *
     * @param list<Middleware> $middlewares DBAL middlewares of the connection
     *
     * @throws SkippedWithMessageException when the platform's URL is not set
     */
    public static function open(string $source, string $tablePrefix = '', string $tableNamePrefix = 'nowo_http_log_', bool $pathPrefix = false, array $middlewares = []): UsageTestDatabase
    {
        if ($source === 'sqlite') {
            return new UsageTestDatabase(true, $tablePrefix, $tableNamePrefix, rollup: true, pathPrefix: $pathPrefix);
        }

        $params = self::params($source);
        self::dropTables($params);

        return new UsageTestDatabase(true, $tablePrefix, $tableNamePrefix, $params, rollup: true, pathPrefix: $pathPrefix, middlewares: $middlewares);
    }

    /**
     * Drops the HttpLog tables of every prefix variant the tests use, and their PostgreSQL id sequences: DBAL 3 + ORM
     * map the id to a SEQUENCE on PostgreSQL, which dropping the table leaves behind (the next schema create would
     * then fail with 42P07).
     *
     * @param array<string, mixed> $params
     */
    public static function dropTables(array $params): void
    {
        $connection = DriverManager::getConnection($params);
        foreach (['entry', 'daily', 'event_seen'] as $suffix) {
            foreach (['nowo_http_log_', 'http_log_', 'app_nowo_http_log_'] as $prefix) {
                $connection->executeStatement('DROP TABLE IF EXISTS ' . $prefix . $suffix);
                if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                    $connection->executeStatement('DROP SEQUENCE IF EXISTS ' . $prefix . $suffix . '_id_seq');
                }
            }
        }
        $connection->close();
    }

    /**
     * DBAL parameters of a server platform's URL.
     *
     * @throws SkippedWithMessageException when the platform's URL is not set
     *
     * @return array<string, mixed>
     */
    public static function params(string $source): array
    {
        $url = getenv($source);
        if (!is_string($url) || $url === '') {
            throw new SkippedWithMessageException($source . ' is not set.');
        }

        return (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url);
    }
}
