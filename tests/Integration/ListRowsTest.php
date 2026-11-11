<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\HttpLogBundle\Doctrine\PathPrefixColumnListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Model\HttpLogListCursor;
use Nowo\HttpLogBundle\Model\HttpLogListRow;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\HttpLogChannel;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function implode;
use function intdiv;
use function range;
use function sprintf;
use function str_contains;
use function str_repeat;

/**
 * PERF-1147-02: the admin list reads a scalar projection in (created_at, id) order by keyset, and the prefix filters
 * match through indexes. SQLite always; MySQL / MariaDB and PostgreSQL with HTTPLOG_TEST_MYSQL_URL / HTTPLOG_TEST_PGSQL_URL.
 */
final class ListRowsTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function platforms(): iterable
    {
        return RollupTestPlatforms::platforms();
    }

    #[Test]
    #[DataProvider('platforms')]
    public function pagesWalkTheStableOrderByKeysetInBothDirections(string $source): void
    {
        $db       = RollupTestPlatforms::open($source);
        $ids      = $this->seed($db, 13);
        $expected = $this->expectedOrder($db);
        self::assertCount(13, $expected);
        $repository = $db->repository();

        $one = $repository->findListRows([], 1, 5);
        self::assertSame(13, $one['total']);
        self::assertFalse($one['total_capped']);
        self::assertSame(array_slice($expected, 0, 5), $this->ids($one['items']));
        self::assertNotNull($one['cursor']);
        self::assertSame(1, $one['cursor']->page);

        $two = $repository->findListRows([], 2, 5, $one['cursor']);
        self::assertSame(array_slice($expected, 5, 5), $this->ids($two['items']));
        $three = $repository->findListRows([], 3, 5, $two['cursor']);
        self::assertSame(array_slice($expected, 10, 3), $this->ids($three['items']), 'the last page is short');

        // back from page 3, a jump from page 1, a reload: the same rows
        self::assertSame($this->ids($two['items']), $this->ids($repository->findListRows([], 2, 5, $three['cursor'])['items']));
        self::assertSame($this->ids($three['items']), $this->ids($repository->findListRows([], 3, 5, $one['cursor'])['items']));
        self::assertSame($this->ids($one['items']), $this->ids($repository->findListRows([], 1, 5, $three['cursor'])['items']));
        self::assertSame($this->ids($two['items']), $this->ids($repository->findListRows([], 2, 5, $two['cursor'])['items']));
        self::assertSame($this->ids($one['items']), $this->ids($repository->findListRows([], 1, 5, $two['cursor'])['items']), 'page 1 is always the newest rows');

        // a 1.16 ?page= link without a cursor: by offset, same rows
        self::assertSame($this->ids($two['items']), $this->ids($repository->findListRows([], 2, 5)['items']));
        self::assertCount(13, $ids);
    }

    #[Test]
    #[DataProvider('platforms')]
    public function aLargeMatchSetIsWalkedByKeysetAndASmallOneByPrimaryKey(string $source): void
    {
        $db    = RollupTestPlatforms::open($source);
        $count = HttpLogEntryRepository::SMALL_MATCH_SET + 25;
        foreach (range(1, $count) as $i) {
            $entry = (new HttpLogEntry())->setPath('/bulk/' . $i)->setStatusCode($i % 50 === 0 ? 500 : 200)
                ->setCreatedAt(new DateTimeImmutable(sprintf('2026-08-01 10:%02d:%02d', intdiv($i, 60) % 60, $i % 60)));
            $db->entityManager->persist($entry);
        }
        $db->entityManager->flush();
        $db->entityManager->clear();
        $expected   = $this->expectedOrder($db);
        $repository = $db->repository();

        // more than SMALL_MATCH_SET matches: keyset over the created_at index, page after page
        $seen   = [];
        $cursor = null;
        for ($page = 1; $page <= 6; ++$page) {
            $result = $repository->findListRows([], $page, 100, $cursor);
            self::assertSame($count, $result['total']);
            $seen   = array_merge($seen, $this->ids($result['items']));
            $cursor = $result['cursor'];
        }
        self::assertSame($expected, $seen);
        self::assertSame(array_slice($expected, 400, 100), $this->ids($repository->findListRows([], 5, 100, $cursor)['items']), 'back from page 6');

        // a few matches: same pages, read by primary key
        $errors = array_map(intval(...), $db->connection()->fetchFirstColumn(sprintf('SELECT id FROM %s WHERE status_code = 500 ORDER BY created_at DESC, id DESC', $db->table())));
        $first  = $repository->findListRows(['statusCode' => 500], 1, 4);
        $second = $repository->findListRows(['statusCode' => 500], 2, 4, $first['cursor']);
        $none   = $repository->findListRows(['statusCode' => 418], 1, 4);
        self::assertSame(array_slice($errors, 0, 4), $this->ids($first['items']));
        self::assertSame(array_slice($errors, 4, 4), $this->ids($second['items']));
        self::assertSame(['items' => [], 'total' => 0, 'total_capped' => false, 'page' => 1, 'cursor' => null], $none);
    }

    #[Test]
    #[DataProvider('platforms')]
    public function aPageReadFromItsCursorIsStableWhenNewerRowsArrive(string $source): void
    {
        $db         = RollupTestPlatforms::open($source);
        $repository = $db->repository();
        $this->seed($db, 10);
        $one = $repository->findListRows([], 1, 4);
        $two = $repository->findListRows([], 2, 4, $one['cursor']);

        $this->persist($db, '/newer', new DateTimeImmutable('2026-09-01 12:00:00'));
        $this->persist($db, '/newer-too', new DateTimeImmutable('2026-09-01 12:00:01'));

        $three    = $repository->findListRows([], 3, 4, $two['cursor']);
        $byOffset = $repository->findListRows([], 3, 4);
        self::assertSame($this->ids(array_slice(array_merge($one['items'], $two['items']), 0)), array_slice($this->expectedOrder($db), 2, 8), 'the new rows sit on top');
        self::assertNotSame($this->ids($three['items']), $this->ids($byOffset['items']), 'OFFSET would repeat two rows of page 2');
        self::assertSame(array_slice($this->expectedOrder($db), 10, 2), $this->ids($three['items']));
    }

    #[Test]
    #[DataProvider('platforms')]
    public function aStaleCursorFallsBackToTheOffsetRead(string $source): void
    {
        $db         = RollupTestPlatforms::open($source);
        $repository = $db->repository();
        $this->seed($db, 12);
        $one   = $repository->findListRows([], 1, 4);
        $two   = $repository->findListRows([], 2, 4, $one['cursor']);
        $three = $repository->findListRows([], 3, 4, $two['cursor']);

        // every row older than page 2 is gone and newer ones arrived: page 3 from page 2's cursor finds none, the
        // offset read takes over
        $db->connection()->executeStatement(sprintf('DELETE FROM %s WHERE id IN (%s)', $db->table(), implode(', ', $this->ids($three['items']))));
        foreach (range(1, 4) as $i) {
            $this->persist($db, '/newer/' . $i, new DateTimeImmutable('2026-09-01 12:00:0' . $i));
        }
        $again = $repository->findListRows([], 3, 4, $two['cursor']);
        self::assertSame(3, $again['page']);
        self::assertSame(array_slice($this->expectedOrder($db), 8, 4), $this->ids($again['items']));
        self::assertSame($this->ids($two['items']), $this->ids($again['items']));

        // a newer page whose rows are gone: same fallback
        $db->connection()->executeStatement(sprintf('DELETE FROM %s WHERE created_at > ?', $db->table()), ['2026-08-01 10:00:59']);
        $db->connection()->executeStatement(sprintf('DELETE FROM %s WHERE id IN (%s)', $db->table(), implode(', ', $this->ids($one['items']))));
        $first = $two['items'][0];
        $back  = $repository->findListRows([], 2, 2, new HttpLogListCursor(3, $first->createdAt, $first->id, $first->createdAt, $first->id));
        self::assertSame(2, $back['page']);
        self::assertSame(array_slice($this->expectedOrder($db), 2, 2), $this->ids($back['items']));
    }

    #[Test]
    #[DataProvider('platforms')]
    public function theProjectionCarriesTheListedColumnsOnly(string $source): void
    {
        $db    = RollupTestPlatforms::open($source);
        $entry = (new HttpLogEntry())
            ->setMethod('PATCH')
            ->setPath('/api/things/7')
            ->setStatusCode(418)
            ->setDurationMs(12.5)
            ->setClientIp('192.0.2.7')
            ->setRouteName('things_patch')
            ->setHost('api.example.test')
            ->setScheme('https')
            ->setUserIdentifier('user-7')
            ->setRequestId('req-7')
            ->setResponseBody(str_repeat('x', 4096))
            ->setRequestHeaders(['accept' => ['application/json']])
            ->setCreatedAt(new DateTimeImmutable('2026-09-01 08:00:00'));
        $db->entityManager->persist($entry);
        $db->entityManager->flush();
        $db->entityManager->clear();

        $rows = $db->repository()->findListRows([], 1, 10)['items'];

        self::assertCount(1, $rows);
        self::assertEquals(new HttpLogListRow((int) $entry->getId(), new DateTimeImmutable('2026-09-01 08:00:00'), 'PATCH', '/api/things/7', 418, 12.5, '192.0.2.7', routeName: 'things_patch', host: 'api.example.test', scheme: 'https', userIdentifier: 'user-7', requestId: 'req-7'), $rows[0]);
        self::assertFalse($db->entityManager->getUnitOfWork()->size() > 0, 'no entity is loaded into the identity map');
    }

    #[Test]
    #[DataProvider('platforms')]
    public function prefixFiltersMatchTheStartOfTheValueThroughThePathPrefixColumn(string $source): void
    {
        $db = RollupTestPlatforms::open($source, pathPrefix: true);
        self::assertTrue($db->repository()->hasPathPrefixColumn());
        $long = '/long/' . str_repeat('a', 200);
        $this->persist($db, '/api/users/1', new DateTimeImmutable('2026-09-01 08:00:00'), 'api_users_show', '10.1.2.3');
        $this->persist($db, '/admin/api/users', new DateTimeImmutable('2026-09-01 08:00:01'), 'admin_users', '10.9.9.9');
        $this->persist($db, '/a_b/x', new DateTimeImmutable('2026-09-01 08:00:02'), 'axb', '10.1.9.9');
        $this->persist($db, '/axb/x', new DateTimeImmutable('2026-09-01 08:00:03'), 'a_b', '192.0.2.1');
        $this->persist($db, $long . 'b', new DateTimeImmutable('2026-09-01 08:00:04'));
        $this->persist($db, $long . 'c', new DateTimeImmutable('2026-09-01 08:00:05'));
        $repository = $db->repository();

        self::assertSame(HttpLogEntry::pathPrefixOf($long . 'b'), $db->connection()->fetchOne(sprintf('SELECT path_prefix FROM %s WHERE path = ?', $db->table()), [$long . 'b']));
        self::assertSame(['/api/users/1'], $this->paths($repository->findListRows(['path' => '/api/'], 1, 10)['items']), 'a prefix, not anywhere in the path');
        self::assertSame(['/a_b/x'], $this->paths($repository->findListRows(['path' => '/a_b'], 1, 10)['items']), '_ matches literally');
        self::assertSame([], $this->paths($repository->findListRows(['path' => '/a%'], 1, 10)['items']), '% matches literally');
        self::assertSame([$long . 'b'], $this->paths($repository->findListRows(['path' => $long . 'b'], 1, 10)['items']), 'a needle longer than the column checks the full path');
        self::assertSame([$long . 'c', $long . 'b'], $this->paths($repository->findListRows(['q' => $long], 1, 10)['items']));
        self::assertSame([$long . 'c'], $this->paths($repository->findListRows(['q' => $long . 'c'], 1, 10)['items']));
        // q: path, route or client IP prefix
        self::assertSame(['/a_b/x', '/api/users/1'], $this->paths($repository->findListRows(['q' => '10.1.'], 1, 10)['items']));
        self::assertSame(['/axb/x'], $this->paths($repository->findListRows(['q' => 'a_b'], 1, 10)['items']));
        self::assertSame(['/admin/api/users'], $this->paths($repository->findListRows(['q' => 'admin'], 1, 10)['items']));
        self::assertSame(['/api/users/1'], $this->paths($repository->findListRows(['routeName' => 'api_'], 1, 10)['items']));
        self::assertSame(1, $repository->countFiltered(['path' => '/api']));
    }

    #[Test]
    public function usageRowsWrittenOutsideTheOrmGetTheirPathPrefix(): void
    {
        $db   = RollupTestPlatforms::open('sqlite', pathPrefix: true);
        $path = '/v1/charges/' . str_repeat('c', 300);
        (new UsageEntryWriter($db))->write(new UsageCapture(HttpLogChannel::OUTBOUND, new DateTimeImmutable('2026-09-01 08:00:00'), 'POST', $path, 'stripe'));

        self::assertSame(HttpLogEntry::pathPrefixOf($path), $db->connection()->fetchOne(sprintf('SELECT path_prefix FROM %s', $db->table())));
        self::assertSame([$path], $this->paths($db->repository()->findListRows(['path' => '/v1/charges/'], 1, 10)['items']));
    }

    #[Test]
    #[DataProvider('platforms')]
    public function containsFiltersMatchAnywhereWithLiteralWildcards(string $source): void
    {
        $db = RollupTestPlatforms::open($source);
        self::assertFalse($db->repository()->hasPathPrefixColumn());
        $this->persist($db, '/api/users/1', new DateTimeImmutable('2026-09-01 08:00:00'), 'api_users_show');
        $this->persist($db, '/admin/api/users', new DateTimeImmutable('2026-09-01 08:00:01'), 'admin_users');
        $this->persist($db, '/a_b/x', new DateTimeImmutable('2026-09-01 08:00:02'), 'axb');
        $this->persist($db, '/axb/x', new DateTimeImmutable('2026-09-01 08:00:03'), 'a!b');
        $repository = $db->repository();

        self::assertSame(['/admin/api/users', '/api/users/1'], $this->paths($repository->findListRows(['path' => '/api/'], 1, 10)['items']));
        self::assertSame(['/a_b/x'], $this->paths($repository->findListRows(['path' => 'a_b'], 1, 10)['items']));
        self::assertSame(['/axb/x'], $this->paths($repository->findListRows(['q' => 'a!b'], 1, 10)['items']), 'the escape character matches literally');
        self::assertSame(['/admin/api/users'], $this->paths($repository->findListRows(['routeName' => 'n_u'], 1, 10)['items']));
    }

    #[Test]
    public function postgreSqlKeepsAPatternOpsIndexWithoutASchemaDiff(): void
    {
        $db       = RollupTestPlatforms::open('HTTPLOG_TEST_PGSQL_URL', pathPrefix: true);
        $platform = $db->connection()->getDatabasePlatform();
        self::assertInstanceOf(PostgreSQLPlatform::class, $platform);
        $table = $db->table();
        // UPGRADING 1.17: the prefix indexes rebuilt with varchar_pattern_ops (LIKE 'x%' under a non-C collation)
        foreach (['idx_http_log_path_prefix' => PathPrefixColumnListener::COLUMN, 'idx_http_log_route_name' => 'route_name', 'idx_http_log_client_ip' => 'client_ip'] as $index => $column) {
            $db->connection()->executeStatement('DROP INDEX ' . $index);
            $db->connection()->executeStatement(sprintf('CREATE INDEX %s ON %s (%s varchar_pattern_ops, created_at)', $index, $table, $column));
        }

        $diff = (new SchemaTool($db->entityManager))->getUpdateSchemaSql([$db->entityManager->getClassMetadata(HttpLogEntry::class)]);

        self::assertSame([], array_values(array_filter($diff, static fn (string $sql): bool => str_contains($sql, $table) && !str_contains($sql, '_daily') && !str_contains($sql, '_event_seen'))));
    }

    /** @return list<int> ids in insertion order */
    private function seed(UsageTestDatabase $db, int $count): array
    {
        $ids = [];
        foreach (range(1, $count) as $i) {
            // three rows per second: the id breaks the ties
            $ids[] = $this->persist($db, '/p/' . $i, new DateTimeImmutable(sprintf('2026-08-01 10:00:%02d', intdiv($i, 3))));
        }

        return $ids;
    }

    private function persist(UsageTestDatabase $db, string $path, DateTimeImmutable $createdAt, ?string $route = null, ?string $ip = null): int
    {
        $entry = (new HttpLogEntry())->setPath($path)->setRouteName($route)->setClientIp($ip)->setCreatedAt($createdAt);
        $db->entityManager->persist($entry);
        $db->entityManager->flush();
        $db->entityManager->clear();

        return (int) $entry->getId();
    }

    /** @return list<int> every id, newest first */
    private function expectedOrder(UsageTestDatabase $db): array
    {
        return array_map(intval(...), $db->connection()->fetchFirstColumn(sprintf('SELECT id FROM %s ORDER BY created_at DESC, id DESC', $db->table())));
    }

    /**
     * @param list<HttpLogListRow> $rows
     *
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (HttpLogListRow $row): int => $row->id, $rows);
    }

    /**
     * @param list<HttpLogListRow> $rows
     *
     * @return list<string>
     */
    private function paths(array $rows): array
    {
        return array_map(static fn (HttpLogListRow $row): string => $row->path, $rows);
    }
}
