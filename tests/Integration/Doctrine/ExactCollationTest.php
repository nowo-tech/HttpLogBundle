<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nowo\HttpLogBundle\Doctrine\ExactCollation;
use Nowo\HttpLogBundle\Doctrine\ExactColumnsCollationListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataExporter;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubject;
use Nowo\HttpLogBundle\Rollup\RollupSql;
use Nowo\HttpLogBundle\Rollup\RollupTables;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\CallQuery;
use Nowo\HttpLogBundle\Usage\HttpLogUsageReader;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use Nowo\HttpLogBundle\Usage\UsageQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use Stringable;
use Symfony\Component\Clock\MockClock;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function getenv;
use function implode;
use function is_string;
use function ksort;
use function method_exists;
use function preg_match;
use function preg_match_all;
use function sort;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function strtr;

use const PHP_VERSION_ID;

/**
 * REQ-SEC-006 (1.20.1): the identifier columns of the entry and rollup tables get a binary NO PAD collation on MySQL /
 * MariaDB (`utf8mb4_0900_bin` / `utf8mb4_nopad_bin`, `utf8mb4_bin` plus a warning on MySQL < 8.0.17), and look-alike
 * identifiers (`Bob`, `bób`, `bob `, `bob\n`, NBSP, ZWSP) never select `bob`'s rows, nor `TOK-1` the calls of `tok-1`.
 * The database tests run with HTTPLOG_TEST_MYSQL_URL (MySQL 8.4 and MariaDB 11.4 in CI).
 */
final class ExactCollationTest extends TestCase
{
    /** Look-alikes of `bob`. */
    private const LOOK_ALIKES = ['Bob', 'bób', 'bob ', "bob\n", "bob\u{00A0}", "bob\u{200B}", "\u{200B}bob"];

    /** Look-alikes of the tenant key `org-a`. */
    private const TENANT_LOOK_ALIKES = ['ORG-A', 'órg-a', 'org-a ', "org-a\n", "org-a\u{00A0}", "org-a\u{200B}"];

    #[Test]
    public function thePlatformAndTheServerVersionChooseTheCollation(): void
    {
        self::assertSame('utf8mb4_nopad_bin', ExactCollation::forPlatform(new MariaDBPlatform()));
        self::assertSame('utf8mb4_0900_bin', ExactCollation::forPlatform(new MySQL80Platform()));
        self::assertSame('utf8mb4_0900_bin', ExactCollation::forPlatform(new MySQL84Platform(), '8.4.3'));
        self::assertSame('utf8mb4_0900_bin', ExactCollation::forPlatform(new MySQL80Platform(), '8.0.17'));
        self::assertSame('utf8mb4_0900_bin', ExactCollation::forPlatform(new MySQL80Platform(), 'not-a-version'));
        self::assertSame('utf8mb4_bin', ExactCollation::forPlatform(new MySQL80Platform(), '8.0.16'));
        self::assertSame('utf8mb4_bin', ExactCollation::forPlatform(new MySQL80Platform(), '8.0'), 'no patch number: 8.0.0');
        self::assertSame('utf8mb4_bin', ExactCollation::forPlatform(new MySQL80Platform(), '8'));
        self::assertSame('utf8mb4_bin', ExactCollation::forPlatform(new MySQLPlatform(), '5.7.44'));
        self::assertNull(ExactCollation::forPlatform(new PostgreSQLPlatform()));
        self::assertNull(ExactCollation::forPlatform(new SQLitePlatform()));
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string|null, 2: bool}> */
    public static function connections(): iterable
    {
        yield 'MySQL 8.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.4.3'], 'utf8mb4_0900_bin', false];
        yield 'MySQL 8.0 without patch' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.0'], 'utf8mb4_bin', true];
        yield 'MySQL 5.7' => [['driver' => 'pdo_mysql', 'serverVersion' => '5.7.44'], 'utf8mb4_bin', true];
        yield 'MySQL 8.4 replica set' => [['driver' => 'pdo_mysql', 'primary' => ['serverVersion' => '8.4.3'], 'replica' => []], 'utf8mb4_0900_bin', false];
        yield 'MariaDB 11.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '11.4.0-MariaDB'], 'utf8mb4_nopad_bin', false];
        yield 'SQLite' => [['driver' => 'pdo_sqlite', 'memory' => true], null, false];
    }

    /** @param array<string, mixed> $params */
    #[Test]
    #[DataProvider('connections')]
    public function theConnectionChoosesTheCollationAndWarnsAboutTheFallback(array $params, ?string $expected, bool $warns): void
    {
        $logger     = $this->logger();
        $connection = DriverManager::getConnection($params);
        self::assertSame($expected, ExactCollation::forConnection($connection, $logger));
        self::assertSame($warns ? 1 : 0, count($logger->records));
        if ($warns) {
            self::assertStringContainsString('PAD SPACE collation utf8mb4_bin', $logger->records[0]);
        }
    }

    #[Test]
    public function withoutAConfiguredVersionTheServerReportsIt(): void
    {
        if (!(new ReflectionMethod(Connection::class, 'getServerVersion'))->isPublic()) {
            // doctrine/dbal 3 keeps getServerVersion() private: the version is unknown, MySQL 8 gets the NO PAD collation
            $connection = $this->createStub(Connection::class);
            $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
            $connection->method('getParams')->willReturn([]);
            self::assertSame(ExactCollation::MYSQL_NOPAD, ExactCollation::forConnection($connection, $this->logger()));

            return;
        }

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('getParams')->willReturn([]);
        $connection->method('getServerVersion')->willReturn('8.0.16');
        $logger = $this->logger();

        self::assertSame('utf8mb4_bin', ExactCollation::forConnection($connection, $logger));
        self::assertStringContainsString('"8.0.16"', $logger->records[0]);
        self::assertSame('utf8mb4_bin', ExactCollation::forConnection($connection), 'no logger: no warning');
    }

    #[Test]
    public function olderDbalCollatesTheColumnInPlace(): void
    {
        $table = new Table('t');
        $table->addColumn('user_identifier', Types::STRING, ['length' => 255]);

        self::assertNull(ExactCollation::collate($table, 'user_identifier', 'utf8mb4_0900_bin', false));
        self::assertSame('utf8mb4_0900_bin', $table->getColumn('user_identifier')->getPlatformOption('collation'));
    }

    #[Test]
    public function credentialListsCompareEveryValueAsBinaryOnMysql(): void
    {
        self::assertSame('(c IN (:i0, :i1) AND CAST(c AS BINARY) IN (CAST(:i0 AS BINARY), CAST(:i1 AS BINARY)))', RollupSql::exactIn(new MySQL80Platform(), 'c', [':i0', ':i1']));
        self::assertSame('c IN (:i0)', RollupSql::exactIn(new PostgreSQLPlatform(), 'c', [':i0']));
    }

    #[Test]
    public function theRollupTablesTakeTheCollationOrTheDeprecatedFlag(): void
    {
        foreach ([[true, 'utf8mb4_bin'], ['utf8mb4_nopad_bin', 'utf8mb4_nopad_bin'], [false, null], ['', null]] as [$mysql, $expected]) {
            $schema = new Schema();
            (new RollupTables())->addTo($schema, $mysql);
            $column = $schema->getTable('nowo_http_log_daily')->getColumn('tenant_key');
            self::assertSame($expected, $column->hasPlatformOption('collation') ? $column->getPlatformOption('collation') : null);
        }
    }

    /** @return iterable<string, array{0: string, 1: string|null}> */
    public static function schemas(): iterable
    {
        yield 'MySQL 8.4' => ['8.4.0', 'utf8mb4_0900_bin'];
        yield 'MariaDB 11.4' => ['11.4.0-MariaDB', 'utf8mb4_nopad_bin'];
        yield 'MySQL 5.7' => ['5.7.44', 'utf8mb4_bin'];
    }

    #[Test]
    #[DataProvider('schemas')]
    public function theGeneratedSchemaGivesEveryIdentifierColumnTheCollation(string $serverVersion, string $collation): void
    {
        $params = ['driver' => 'pdo_mysql', 'serverVersion' => $serverVersion];
        if (!(new ReflectionMethod(Connection::class, 'getServerVersion'))->isPublic()) {
            // doctrine/dbal 3 reads the default table options from the server while it generates the schema
            $url = getenv('HTTPLOG_TEST_MYSQL_URL');
            if (!is_string($url) || $url === '') {
                self::markTestSkipped('doctrine/dbal 3 needs HTTPLOG_TEST_MYSQL_URL to generate a MySQL schema.');
            }
            $params = ['serverVersion' => $serverVersion] + RollupTestPlatforms::params('HTTPLOG_TEST_MYSQL_URL');
        }
        $logger = $this->logger();
        $db     = new UsageTestDatabase(true, connectionParams: $params, createSchema: false, rollup: true, logger: $logger, errorCode: true);
        $sql    = implode("\n", (new SchemaTool($db->entityManager))->getCreateSchemaSql([$db->entityManager->getClassMetadata(HttpLogEntry::class)]));

        foreach ([...ExactColumnsCollationListener::COLUMNS, 'channel'] as $column) {
            self::assertMatchesRegularExpression(sprintf('/\b%s VARCHAR\(\d+\) CHARACTER SET utf8mb4 (DEFAULT [^,]+ )?(NOT NULL )?COLLATE `%s`/', $column, $collation), $sql, $column);
        }
        foreach (['provider', 'credential_kind', 'credential_id', 'tenant_key'] as $column) {
            self::assertSame(2, preg_match_all(sprintf('/\b%s VARCHAR\(\d+\) CHARACTER SET utf8mb4 [^,]*COLLATE `%s`/', $column, $collation), $sql), $column . ': entry and rollup');
        }
        self::assertCount($collation === 'utf8mb4_bin' ? 1 : 0, $logger->records, 'one warning per entry table');
        self::assertSame(0, preg_match('/\bpath VARCHAR\(2048\)[^,]*COLLATE/', $sql), 'other columns keep the table collation');
    }

    #[Test]
    public function otherTablesAndManagersAreLeftAlone(): void
    {
        $sqlite = new UsageTestDatabase(createSchema: false, rollup: true);
        self::assertStringNotContainsString('COLLATE', implode("\n", (new SchemaTool($sqlite->entityManager))->getCreateSchemaSql([$sqlite->entityManager->getClassMetadata(HttpLogEntry::class)])));

        // A manager that does not map the entry (audit XI: the schema tool's own manager decides).
        $configuration = ORMSetup::createAttributeMetadataConfiguration([], true);
        $configuration->setMetadataDriverImpl(new MappingDriverChain());
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $other  = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], $configuration), $configuration);
        $schema = $this->entrySchema();
        $args   = new GenerateSchemaEventArgs($other, $schema);
        (new ExactColumnsCollationListener())->postGenerateSchema($args);
        self::assertSame($schema, $args->getSchema(), 'no mapping for the entry');
        self::assertFalse($schema->getTable('nowo_http_log_entry')->getColumn('user_identifier')->hasPlatformOption('collation'));

        // The entry is mapped, but the generated schema does not hold its table.
        $db    = new UsageTestDatabase(connectionParams: ['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], createSchema: false);
        $empty = new Schema();
        $args  = new GenerateSchemaEventArgs($db->entityManager, $empty);
        (new ExactColumnsCollationListener($db))->postGenerateSchema($args);
        self::assertSame($empty, $args->getSchema(), 'no entry table');
        self::assertFalse($db->connection()->isConnected());
    }

    /**
     * Audit XI (1.23.1): a foreign key and an index starting on the entry table are kept (1.20.1–1.23.0 replaced the
     * table on postGenerateSchemaTable, and ORM 3.7–3.8 then added its foreign keys to the replaced object).
     */
    #[Test]
    public function aForeignKeyAndAnIndexOfTheEntryTableAreKept(): void
    {
        $db     = new UsageTestDatabase(connectionParams: ['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], createSchema: false);
        $schema = $this->entrySchema();
        $parent = $schema->createTable('host_account');
        $parent->addColumn('id', Types::INTEGER);
        $parent->setPrimaryKey(['id']);
        $entry = $schema->getTable('nowo_http_log_entry');
        $entry->addColumn('account_id', Types::INTEGER, ['notnull' => false]);
        $entry->addIndex(['account_id'], 'idx_account');
        $entry->addForeignKeyConstraint('host_account', ['account_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_account');
        $before = $this->shape($schema);

        $args = new GenerateSchemaEventArgs($db->entityManager, $schema);
        (new ExactColumnsCollationListener())->postGenerateSchema($args);

        self::assertSame($before, $this->shape($args->getSchema()));
        self::assertContains('account_id->host_account(id) SET NULL', $this->shape($args->getSchema())['nowo_http_log_entry']['fks']);
        self::assertSame('utf8mb4_0900_bin', $args->getSchema()->getTable('nowo_http_log_entry')->getColumn('user_identifier')->getPlatformOption('collation'));
        self::assertFalse($args->getSchema()->getTable('nowo_http_log_entry')->getColumn('account_id')->hasPlatformOption('collation'));
    }

    /**
     * Audit XI (1.23.1): the schema generated with the listener has every foreign key and index of the one generated
     * without it, on every platform.
     *
     * @param array<string, mixed> $params
     */
    #[Test]
    #[DataProvider('connections')]
    public function theGeneratedSchemaKeepsEveryForeignKeyAndIndex(array $params, ?string $expected, bool $warns): void
    {
        unset($expected, $warns);
        if (($params['driver'] ?? null) === 'pdo_mysql' && !(new ReflectionMethod(Connection::class, 'getServerVersion'))->isPublic()) {
            // doctrine/dbal 3 reads the default table options from the server while it generates the schema
            $url = getenv('HTTPLOG_TEST_MYSQL_URL');
            if (!is_string($url) || $url === '') {
                self::markTestSkipped('doctrine/dbal 3 needs HTTPLOG_TEST_MYSQL_URL to generate a MySQL schema.');
            }
            $params += RollupTestPlatforms::params('HTTPLOG_TEST_MYSQL_URL');
        }
        $generate = static function (bool $listener) use ($params): Schema {
            $db = new UsageTestDatabase(true, connectionParams: $params, createSchema: false, rollup: true, exactCollation: $listener);

            return (new SchemaTool($db->entityManager))->getSchemaFromMetadata([$db->entityManager->getClassMetadata(HttpLogEntry::class)]);
        };

        self::assertSame($this->shape($generate(false)), $this->shape($generate(true)));
    }

    /**
     * Audit XI on MySQL 8.4 / MariaDB 11.4 (HTTPLOG_TEST_MYSQL_URL): the entry table created with the listener has every
     * foreign key and secondary index of the schema generated without it, and the identifier columns are exact.
     */
    #[Test]
    public function theCreatedEntryTableKeepsEveryForeignKeyAndIndex(): void
    {
        $db         = RollupTestPlatforms::open('HTTPLOG_TEST_MYSQL_URL');
        $connection = $db->connection();
        $expected   = $this->shape((new SchemaTool(
            (new UsageTestDatabase(true, connectionParams: RollupTestPlatforms::params('HTTPLOG_TEST_MYSQL_URL'), createSchema: false, rollup: true, exactCollation: false))->entityManager,
        ))->getSchemaFromMetadata([$db->entityManager->getClassMetadata(HttpLogEntry::class)]));
        $schemaManager = $connection->createSchemaManager();
        foreach (['nowo_http_log_entry', 'nowo_http_log_daily', 'nowo_http_log_event_seen'] as $name) {
            // @phpstan-ignore function.alreadyNarrowedType (introspectTableByUnquotedName() exists from DBAL 4.3; DBAL 3 has introspectTable())
            $table  = method_exists($schemaManager, 'introspectTableByUnquotedName') ? $schemaManager->introspectTableByUnquotedName($name) : $schemaManager->introspectTable($name);
            $actual = $this->shape(new Schema([$table]))[$name];
            self::assertSame($expected[$name]['fks'], $actual['fks'], $name);
            self::assertSame($this->secondary($expected[$name]['indexes']), $this->secondary($actual['indexes']), $name);
        }
        self::assertSame(
            ExactCollation::forConnection($connection),
            $connection->fetchOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nowo_http_log_entry' AND COLUMN_NAME = 'user_identifier'"),
        );
    }

    #[Test]
    public function theFormerTableEventDoesNothing(): void
    {
        $table = new Table('nowo_http_log_entry');
        $table->addColumn('user_identifier', Types::STRING, ['length' => 255]);
        $args = new GenerateSchemaTableEventArgs(new ClassMetadata(HttpLogEntry::class), new Schema(), $table);

        (new ExactColumnsCollationListener())->postGenerateSchemaTable($args);

        self::assertSame($table, $args->getClassTable());
        self::assertFalse($table->getColumn('user_identifier')->hasPlatformOption('collation'));
    }

    #[Test]
    public function lookAlikeIdentifiersNeverMatchOnTheDatabase(): void
    {
        $db         = RollupTestPlatforms::open('HTTPLOG_TEST_MYSQL_URL');
        $connection = $db->connection();
        $store      = new UsageRollupStore($db, $db->rollupTables, new MockClock(new DateTimeImmutable('2026-11-08T12:00:00+00:00')));
        $writer     = new UsageEntryWriter($db, $store);
        foreach (['tok-1', 'tok-1', 'tok-2'] as $credential) {
            $writer->write(new UsageCapture(channel: 'api', createdAt: new DateTimeImmutable('2026-11-08T09:00:00+00:00'), method: 'GET', path: '/api/x', statusCode: 200, durationMs: 10.0, tenantKey: 'org-a', credentialKind: 'personal_token', credentialId: $credential, row: true, rollup: true));
        }
        $entry = (new HttpLogEntry())->setUserIdentifier('bob')->setClientIp('10.0.0.1')->setCreatedAt(new DateTimeImmutable('2026-11-08 10:00:00'))->setMethod('GET')->setScheme('https')->setHost('app.example.test')->setPath('/account');
        $db->entityManager->persist($entry);
        $db->entityManager->flush();
        $db->entityManager->clear();

        foreach (self::LOOK_ALIKES as $lookAlike) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $db->table() . ' WHERE user_identifier = ?', [$lookAlike]), 'entry: ' . $lookAlike);
            self::assertSame(0, $this->exporter($db)->export(HttpLogPersonalDataSubject::fromUserIdentifier($lookAlike))['total'], 'export: ' . $lookAlike);
        }
        foreach (self::TENANT_LOOK_ALIKES as $lookAlike) {
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $db->rollupTables->daily . ' WHERE tenant_key = ?', [$lookAlike]), 'rollup: ' . $lookAlike);
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $db->table() . ' WHERE tenant_key = ?', [$lookAlike]), 'entry: ' . $lookAlike);
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $db->table() . ' WHERE user_identifier = ?', ['bob']));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $db->rollupTables->daily . ' WHERE tenant_key = ?', ['org-a']));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $db->table() . ' WHERE client_ip = ?', ['10.0.0.1']));

        $reader = new HttpLogUsageReader($db, $store);
        $calls  = static fn (string ...$ids): int => count($reader->calls(CallQuery::forTenant('org-a')->withCredential('personal_token', ...$ids))->items);
        self::assertSame(2, $calls('tok-1'));
        self::assertSame(0, $calls('TOK-1'), 'calls() never folds a credential id');
        self::assertSame(1, $calls('TOK-1', 'tok-2'));
        self::assertSame(['tok-1'], array_keys($reader->lastUsed(UsageQuery::forTenant('org-a'), 'personal_token', ['tok-1', 'TOK-2'])));
        self::assertSame([], $reader->daily(UsageQuery::forTenant('org-a')->withCredential('personal_token', 'TOK-1')));
        self::assertSame([], $reader->daily(UsageQuery::forTenant('org-a')->withProviders('CLICKUP')));

        $connection->close();
    }

    private function exporter(UsageTestDatabase $db): HttpLogPersonalDataExporter
    {
        return new HttpLogPersonalDataExporter($db->repository(), 10, ['purge_batch_size' => 2]);
    }

    /** @return AbstractLogger&object{records: list<string>} */
    /** The generated schema of the entry table alone (SQLite: no server needed on doctrine/dbal 3). */
    private function entrySchema(): Schema
    {
        $db = new UsageTestDatabase(true, createSchema: false, exactCollation: false);

        return (new SchemaTool($db->entityManager))->getSchemaFromMetadata([$db->entityManager->getClassMetadata(HttpLogEntry::class)]);
    }

    /**
     * Foreign keys (local columns, foreign table and columns, ON DELETE) and indexes (columns, uniqueness) per table.
     *
     * @return array<string, array{fks: list<string>, indexes: list<string>}>
     */
    private function shape(Schema $schema): array
    {
        $shape = [];
        foreach ($schema->getTables() as $table) {
            $fks = [];
            foreach ($table->getForeignKeys() as $fk) {
                $fks[] = implode(',', $fk->getLocalColumns()) . '->' . $fk->getForeignTableName() . '(' . implode(',', $fk->getForeignColumns()) . ') ' . ($fk->getOption('onDelete') ?? '-');
            }
            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexes[] = ($index->isPrimary() ? 'primary ' : ($index->isUnique() ? 'unique ' : '')) . implode(',', array_map(strtolower(...), $index->getColumns()));
            }
            // Introspected names come quoted ("user_id", `user_id`); generated ones do not.
            $fks     = str_replace(['"', '`'], '', $fks);
            $indexes = str_replace(['"', '`'], '', $indexes);
            sort($fks);
            sort($indexes);
            $shape[strtolower(str_replace(['"', '`'], '', $table->getName()))] = ['fks' => $fks, 'indexes' => $indexes];
        }
        ksort($shape);

        return $shape;
    }

    /**
     * @param list<string> $indexes
     *
     * @return list<string>
     */
    private function secondary(array $indexes): array
    {
        return array_values(array_filter($indexes, static fn (string $index): bool => !str_starts_with($index, 'primary ')));
    }

    private function logger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = strtr((string) $message, ['{version}' => (string) ($context['version'] ?? '')]);
            }
        };
    }
}
