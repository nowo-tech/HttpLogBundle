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
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
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
use stdClass;
use Stringable;
use Symfony\Component\Clock\MockClock;

use function array_keys;
use function count;
use function implode;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function strtr;

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
        $logger     = self::logger();
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
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('getParams')->willReturn([]);
        $connection->method('getServerVersion')->willReturn('8.0.16');
        $logger = self::logger();

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
        $logger = self::logger();
        $db     = new UsageTestDatabase(true, connectionParams: ['driver' => 'pdo_mysql', 'serverVersion' => $serverVersion], createSchema: false, rollup: true, logger: $logger);
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

        $db    = new UsageTestDatabase(connectionParams: ['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], createSchema: false);
        $table = new Table('nowo_http_log_entry');
        $table->addColumn('user_identifier', Types::STRING, ['length' => 255]);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);
        $args = new GenerateSchemaTableEventArgs($db->entityManager->getClassMetadata(HttpLogEntry::class), new Schema(), $table);
        (new ExactColumnsCollationListener($registry))->postGenerateSchemaTable($args);
        self::assertSame($table, $args->getClassTable(), 'no manager for the entry');

        $other = new GenerateSchemaTableEventArgs(new ClassMetadata(stdClass::class), new Schema(), $table);
        (new ExactColumnsCollationListener($db))->postGenerateSchemaTable($other);
        self::assertSame($table, $other->getClassTable(), 'another entity');
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
    private static function logger(): AbstractLogger
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
