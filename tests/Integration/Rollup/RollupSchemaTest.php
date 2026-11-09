<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Rollup;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Rollup\RollupSchemaListener;
use Nowo\HttpLogBundle\Rollup\RollupTables;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_filter;
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
use function str_replace;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const PHP_VERSION_ID;

/**
 * HL-1 §8.1 / §8.3: the rollup tables exist only while the rollup maps them, and the documented DDL (docs/UPGRADING.md,
 * read by this test) brings a 1.15 schema to exactly the mapped one: the schema diff is empty afterwards, with the
 * default and with prefixed names, on SQLite, and on MySQL / MariaDB and PostgreSQL when HTTPLOG_TEST_MYSQL_URL /
 * HTTPLOG_TEST_PGSQL_URL point at a throwaway database.
 */
final class RollupSchemaTest extends TestCase
{
    #[Test]
    public function withoutTheRollupTheSchemaHasNoRollupTable(): void
    {
        $sql = implode("\n", (new SchemaTool((new UsageTestDatabase(createSchema: false))->entityManager))->getCreateSchemaSql([(new UsageTestDatabase(createSchema: false))->entityManager->getClassMetadata(HttpLogEntry::class)]));

        self::assertStringNotContainsString('_daily', $sql);
        self::assertStringNotContainsString('event_seen', $sql);
    }

    #[Test]
    public function theListenerAddsBothTablesOnceAndOnlyForTheEntrysManager(): void
    {
        $db       = new UsageTestDatabase(createSchema: false, rollup: true);
        $metadata = $db->entityManager->getClassMetadata(HttpLogEntry::class);
        $sql      = implode("\n", (new SchemaTool($db->entityManager))->getCreateSchemaSql([$metadata]));
        self::assertStringContainsString('CREATE TABLE nowo_http_log_daily', $sql);
        self::assertStringContainsString('CREATE TABLE nowo_http_log_event_seen', $sql);

        $schema = new Schema();
        $schema->createTable('nowo_http_log_entry');
        $listener = new RollupSchemaListener(new RollupTables());
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($db->entityManager, $schema));
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($db->entityManager, $schema));
        self::assertCount(3, $schema->getTables());

        // the schema of another EntityManager (no entry table in it) gets nothing
        $empty = new Schema();
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($db->entityManager, $empty));
        self::assertSame([], $empty->getTables());

        // nor does one that cannot load the entity's metadata
        $configuration = ORMSetup::createAttributeMetadataConfiguration([sys_get_temp_dir()], true);
        $configuration->setMetadataDriverImpl(new MappingDriverChain());
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $other = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration, new EventManager());
        $listener->postGenerateSchema(new GenerateSchemaEventArgs($other, $empty));
        self::assertSame([], $empty->getTables());
    }

    #[Test]
    public function prefixesNameTheTablesAndTheIndexes(): void
    {
        $tables = RollupTables::fromPrefixes('app_', 'http_log_');

        self::assertSame(['app_http_log_daily', 'app_http_log_event_seen', 'app_idx_http_log_seen_day'], [$tables->daily, $tables->eventSeen, $tables->index(RollupTables::SEEN_INDEX)]);
        $schema = new Schema();
        $tables->addTo($schema);
        self::assertTrue($schema->getTable('app_http_log_daily')->hasIndex('app_uniq_http_log_daily_key'));
        self::assertTrue($schema->getTable('app_http_log_daily')->hasIndex('app_idx_http_log_daily_cred'));
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
        $file                            = sys_get_temp_dir() . '/http-log-rollup-schema-' . bin2hex(random_bytes(6)) . '.sqlite';
        $params                          = ['driver' => 'pdo_sqlite', 'path' => $file];
        if ($platform !== 'sqlite') {
            $url = getenv($source);
            if (!is_string($url) || $url === '') {
                self::markTestSkipped($source . ' is not set.');
            }
            $params = (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($url);
            $this->dropTables($params);
        }

        // the 1.15 schema (entry with the usage columns) first
        $old = new UsageTestDatabase(true, $tablePrefix, $tableNamePrefix, $params);
        foreach ($this->ddl($platform, $old->rollupTables, $tablePrefix) as $statement) {
            $old->connection()->executeStatement($statement);
        }
        $old->connection()->close();

        $new  = new UsageTestDatabase(true, $tablePrefix, $tableNamePrefix, $params, false, true);
        $diff = (new SchemaTool($new->entityManager))->getUpdateSchemaSql([$new->entityManager->getClassMetadata(HttpLogEntry::class)]);
        $new->connection()->close();

        if ($platform === 'sqlite') {
            @unlink($file);
        } else {
            $this->dropTables($params);
        }

        self::assertSame([], $diff);
    }

    /**
     * Statements of the `hl1-rollup-ddl:<platform>` block of docs/UPGRADING.md, renamed for the variant.
     *
     * @return list<string>
     */
    private function ddl(string $platform, RollupTables $tables, string $tablePrefix): array
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/UPGRADING.md');
        if (preg_match('/<!-- hl1-rollup-ddl:' . preg_quote($platform, '/') . ' -->\s*```sql\n(.*?)```/s', $doc, $match) !== 1) {
            throw new RuntimeException('No rollup DDL block for ' . $platform);
        }
        $sql = str_replace(['nowo_http_log_daily', 'nowo_http_log_event_seen'], [$tables->daily, $tables->eventSeen], $match[1]);
        $sql = str_replace(['INDEX idx_http_log_', 'INDEX uniq_http_log_'], ['INDEX ' . $tablePrefix . 'idx_http_log_', 'INDEX ' . $tablePrefix . 'uniq_http_log_'], $sql);

        return array_values(array_filter(array_map(trim(...), explode(';', $sql)), static fn (string $s): bool => $s !== ''));
    }

    /** @param array<string, mixed> $params */
    private function dropTables(array $params): void
    {
        $connection = DriverManager::getConnection($params);
        foreach (['nowo_http_log_', 'http_log_', 'app_nowo_http_log_'] as $prefix) {
            foreach (['entry', 'daily', 'event_seen'] as $suffix) {
                $connection->executeStatement('DROP TABLE IF EXISTS ' . $prefix . $suffix);
            }
        }
        $connection->close();
    }
}
