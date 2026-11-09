<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\Doctrine\UsageColumnsListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function bin2hex;
use function dirname;
use function explode;
use function file_get_contents;
use function getenv;
use function implode;
use function is_string;
use function preg_match;
use function preg_quote;
use function random_bytes;
use function sort;
use function str_pad;
use function str_replace;
use function sys_get_temp_dir;
use function trim;
use function unlink;

/**
 * HL-1 §8 / Q-O1: the usage columns exist only while a feature maps them, and the documented DDL (docs/UPGRADING.md,
 * read by this test) brings a 1.14 table to exactly the mapped schema: the schema diff is empty afterwards, with the
 * default and with prefixed names. SQLite always; MySQL / MariaDB and PostgreSQL when HTTPLOG_TEST_MYSQL_URL /
 * HTTPLOG_TEST_PGSQL_URL point at an empty database.
 */
final class UsageSchemaTest extends TestCase
{
    /** Mapped fields of 1.14.0 (golden): the default mapping must not change. */
    private const FIELDS_1_14 = [
        'bodyContentType', 'clientIp', 'contentType', 'createdAt', 'durationMs', 'host', 'id', 'method', 'path',
        'queryParams', 'requestBody', 'requestBodyTruncated', 'requestHeaders', 'requestId', 'responseBody',
        'responseBodyStored', 'responseBodyTruncated', 'responseHeaders', 'routeName', 'scheme', 'statusCode',
        'userIdentifier',
    ];

    #[Test]
    public function withoutTheFeatureTheMappingIs114(): void
    {
        $db       = new UsageTestDatabase(usageColumns: false, createSchema: false);
        $metadata = $db->entityManager->getClassMetadata(HttpLogEntry::class);
        $fields   = $metadata->getFieldNames();
        sort($fields);

        self::assertSame(self::FIELDS_1_14, $fields);
        self::assertSame(['idx_http_log_created_at', 'idx_http_log_route_name', 'idx_http_log_status_code', 'idx_http_log_client_ip'], array_keys($metadata->table['indexes'] ?? []));
        $sql = implode("\n", (new SchemaTool($db->entityManager))->getCreateSchemaSql([$metadata]));
        foreach (UsageColumnsListener::COLUMNS as $definition) {
            self::assertStringNotContainsString($definition['column'], $sql);
        }
    }

    #[Test]
    public function theListenerMapsElevenNullableColumnsAndFiveIndexesOnce(): void
    {
        $db       = new UsageTestDatabase(createSchema: false);
        $metadata = $db->entityManager->getClassMetadata(HttpLogEntry::class);

        foreach (UsageColumnsListener::COLUMNS as $field => $definition) {
            self::assertTrue($metadata->isNullable($field), $field);
            self::assertSame($definition['column'], $metadata->getColumnName($field));
        }
        foreach (UsageColumnsListener::INDEXES as $name => $index) {
            self::assertSame($index, $metadata->table['indexes'][$name] ?? null);
        }

        // a second event on the same metadata (or another class) changes nothing
        $listener = new UsageColumnsListener();
        $listener->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $db->entityManager));
        self::assertCount(22 + 11, $metadata->getFieldNames());
    }

    #[Test]
    public function tablePrefixAlsoPrefixesTheUsageIndexes(): void
    {
        $metadata = (new UsageTestDatabase(tablePrefix: 'app_', createSchema: false))->entityManager->getClassMetadata(HttpLogEntry::class);

        self::assertArrayHasKey('app_idx_http_log_provider_created', $metadata->table['indexes'] ?? []);
        self::assertSame('app_nowo_http_log_entry', $metadata->getTableName());
    }

    #[Test]
    public function aTablePrefixTooLongForTheUsageIndexesIsRejected(): void
    {
        $prefix = 'p' . str_replace(' ', '', str_pad('', 34, 'x')) . '_';
        TablePrefixListener::validate($prefix, 'nowo_http_log_');

        $this->expectException(InvalidArgumentException::class);
        TablePrefixListener::validate($prefix, 'nowo_http_log_', true);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function platforms(): iterable
    {
        foreach (['sqlite' => 'sqlite', 'mysql' => 'HTTPLOG_TEST_MYSQL_URL', 'postgresql' => 'HTTPLOG_TEST_PGSQL_URL'] as $platform => $source) {
            foreach (['default' => ['', 'nowo_http_log_'], 'table_name_prefix' => ['', 'http_log_'], 'table_prefix' => ['app_', 'nowo_http_log_']] as $variant => [$tablePrefix, $tableNamePrefix]) {
                yield $platform . ' ' . $variant => [$platform, $source, $tablePrefix . '|' . $tableNamePrefix];
            }
        }
    }

    #[Test]
    #[DataProvider('platforms')]
    public function documentedDdlLeavesNoSchemaDiff(string $platform, string $source, string $prefixes): void
    {
        [$tablePrefix, $tableNamePrefix] = explode('|', $prefixes);
        $file                            = sys_get_temp_dir() . '/http-log-usage-schema-' . bin2hex(random_bytes(6)) . '.sqlite';
        $params                          = ['driver' => 'pdo_sqlite', 'path' => $file];
        if ($platform !== 'sqlite') {
            $url = getenv($source);
            if (!is_string($url) || $url === '') {
                self::markTestSkipped($source . ' is not set.');
            }
            $params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url);
            $this->dropEntryTables($params);
        }

        // 1.14 table first
        $old = new UsageTestDatabase(false, $tablePrefix, $tableNamePrefix, $params);
        foreach ($this->ddl($platform, $old->table(), $tablePrefix) as $statement) {
            $old->connection()->executeStatement($statement);
        }

        $old->connection()->close();

        $new  = new UsageTestDatabase(true, $tablePrefix, $tableNamePrefix, $params, false);
        $diff = (new SchemaTool($new->entityManager))->getUpdateSchemaSql([$new->entityManager->getClassMetadata(HttpLogEntry::class)]);
        $new->connection()->close();

        if ($platform === 'sqlite') {
            @unlink($file);
        } else {
            $this->dropEntryTables($params);
        }

        self::assertSame([], $diff);
    }

    /**
     * Statements of the `hl1-ddl:<platform>` block of docs/UPGRADING.md, renamed for the variant.
     *
     * @return list<string>
     */
    private function ddl(string $platform, string $table, string $tablePrefix): array
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/UPGRADING.md');
        if (preg_match('/<!-- hl1-ddl:' . preg_quote($platform, '/') . ' -->\s*```sql\n(.*?)```/s', $doc, $match) !== 1) {
            throw new RuntimeException('No DDL block for ' . $platform);
        }
        $sql = str_replace('nowo_http_log_entry', $table, $match[1]);
        $sql = str_replace('INDEX idx_http_log_', 'INDEX ' . $tablePrefix . 'idx_http_log_', $sql);

        return array_values(array_filter(array_map(trim(...), explode(';', $sql)), static fn (string $s): bool => $s !== ''));
    }

    /** @param array<string, mixed> $params */
    private function dropEntryTables(array $params): void
    {
        $connection = DriverManager::getConnection($params);
        foreach (['nowo_http_log_entry', 'http_log_entry', 'app_nowo_http_log_entry'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $connection->close();
    }
}
