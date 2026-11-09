<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Rollup;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use LogicException;

use function array_fill;
use function array_keys;
use function array_map;
use function count;
use function implode;
use function sprintf;

/**
 * The bucket upsert of one platform (HL-1 §9.3): MySQL / MariaDB `INSERT … ON DUPLICATE KEY UPDATE`, PostgreSQL and
 * SQLite (>= 3.24) `INSERT … ON CONFLICT (…) DO UPDATE`. Counters are added, `duration_ms_max` and `last_seen_at` keep
 * the larger value, `first_seen_at` the smaller one (a late event can be older than the bucket). Other platforms are
 * refused. Every value is a bound parameter; the identifiers are bundle constants and the validated table name.
 */
final class RollupSql
{
    /**
     * @param list<string> $columns insert columns, in the order of the bound values
     *
     * @throws LogicException on a platform other than MySQL / MariaDB, PostgreSQL or SQLite
     *
     * @return array{sql: string, repeat: list<string>} the statement, and the columns whose values are bound again
     *                                                  after the inserted values (MySQL)
     */
    public static function upsert(AbstractPlatform $platform, string $table, array $columns): array
    {
        $insert = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?')));
        $merged = [...RollupTables::COUNTER_COLUMNS, 'duration_ms_max', 'first_seen_at', 'last_seen_at'];

        if ($platform instanceof AbstractMySQLPlatform) {
            $set   = array_map(static fn (string $c): string => sprintf('%1$s = %1$s + ?', $c), RollupTables::COUNTER_COLUMNS);
            $set[] = 'duration_ms_max = GREATEST(duration_ms_max, ?)';
            $set[] = 'first_seen_at = LEAST(first_seen_at, ?)';
            $set[] = 'last_seen_at = GREATEST(last_seen_at, ?)';

            return ['sql' => $insert . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $set), 'repeat' => $merged];
        }

        if ($platform instanceof PostgreSQLPlatform) {
            $insert = sprintf('INSERT INTO %s AS b (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?')));

            return ['sql' => $insert . self::onConflict('b.', 'EXCLUDED.', 'GREATEST', 'LEAST'), 'repeat' => []];
        }

        if ($platform instanceof SQLitePlatform) {
            return ['sql' => $insert . self::onConflict('', 'excluded.', 'MAX', 'MIN'), 'repeat' => []];
        }

        throw new LogicException(sprintf('The HttpLog rollup supports MySQL / MariaDB, PostgreSQL and SQLite only, not %s.', $platform::class));
    }

    /**
     * Binds the values of `$bucket` for {@see upsert()}.
     *
     * @param array<string, int|string> $bucket column => value
     * @param list<string> $repeat
     *
     * @return list<int|string>
     */
    public static function parameters(array $bucket, array $repeat): array
    {
        $values = [];
        foreach (array_keys($bucket) as $column) {
            $values[] = $bucket[$column];
        }
        foreach ($repeat as $column) {
            $values[] = $bucket[$column];
        }

        return $values;
    }

    private static function onConflict(string $current, string $new, string $greatest, string $least): string
    {
        $set   = array_map(static fn (string $c): string => sprintf('%1$s = %2$s%1$s + %3$s%1$s', $c, $current, $new), RollupTables::COUNTER_COLUMNS);
        $set[] = sprintf('duration_ms_max = %s(%sduration_ms_max, %sduration_ms_max)', $greatest, $current, $new);
        $set[] = sprintf('first_seen_at = %s(%sfirst_seen_at, %sfirst_seen_at)', $least, $current, $new);
        $set[] = sprintf('last_seen_at = %s(%slast_seen_at, %slast_seen_at)', $greatest, $current, $new);

        return sprintf(' ON CONFLICT (%s) DO UPDATE SET %s', implode(', ', RollupTables::KEY_COLUMNS), implode(', ', $set));
    }

    /**
     * Byte-exact comparison of a column with a bound parameter: MySQL's case-insensitive collations would otherwise
     * match `ORG-A` for `org-a` (PostgreSQL and SQLite compare exactly already). The plain `=` keeps the index usable.
     */
    public static function exact(AbstractPlatform $platform, string $column, string $placeholder): string
    {
        if ($platform instanceof AbstractMySQLPlatform) {
            return sprintf('(%1$s = %2$s AND CAST(%1$s AS BINARY) = CAST(%2$s AS BINARY))', $column, $placeholder);
        }

        return sprintf('%s = %s', $column, $placeholder);
    }
}
