<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Doctrine\CorrelationColumnsListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function strtolower;

/**
 * 1.27 `correlation.columns` on real servers (MySQL 8.4, MariaDB 11.4, PostgreSQL 16): the columns and indexes are
 * created with the configured prefixes, rows are written by the usage writer and the web recorder path, and the
 * filters match exactly (case-sensitive, the binary NO PAD collation on MySQL / MariaDB).
 */
#[Group('database')]
final class CorrelationColumnsDbTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function servers(): iterable
    {
        yield 'mysql 8.4' => ['HTTPLOG_TEST_MYSQL_URL'];
        yield 'mariadb 11.4' => ['HTTPLOG_TEST_MARIADB_URL'];
        yield 'postgresql 16' => ['HTTPLOG_TEST_PGSQL_URL'];
    }

    #[Test]
    #[DataProvider('servers')]
    public function theColumnsAreWrittenIndexedAndMatchedExactly(string $source): void
    {
        $db = RollupTestPlatforms::open($source, 'app_', correlation: true);

        try {
            $table   = $db->connection()->createSchemaManager()->introspectTable($db->table());
            $indexes = array_map(static fn ($index): array => array_map(strtolower(...), $index->getColumns()), $table->getIndexes());
            self::assertSame(['correlation_id', 'created_at'], $indexes['app_' . CorrelationColumnsListener::INDEX] ?? null);
            self::assertSame(['upstream_id', 'created_at'], $indexes['app_' . CorrelationColumnsListener::UPSTREAM_INDEX] ?? null);

            $writer = new UsageEntryWriter($db);
            self::assertTrue($writer->write(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', '/v2', provider: 'clickup', correlationId: 'Req-1')));
            self::assertTrue($writer->write(new UsageCapture('mailer', new DateTimeImmutable(), 'SEND', '/', correlationId: 'Req-1')));
            self::assertTrue($writer->write(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', '/v2', provider: 'clickup', correlationId: 'req-1')));
            $db->entityManager->persist((new HttpLogEntry())->setRequestId('Req-1')->setCorrelationId('Req-1')->setUpstreamId('4bf92f3577b34da6a3ce929d0e0e4736')->setPath('/orders'));
            $db->entityManager->flush();
            $db->entityManager->clear();

            $repository = $db->repository();
            self::assertSame(3, $repository->countFiltered(['correlationId' => 'Req-1']));
            self::assertSame(1, $repository->countFiltered(['correlationId' => 'req-1']), 'case-sensitive');
            self::assertSame(0, $repository->countFiltered(['correlationId' => 'Req-1 ']), 'no PAD SPACE match');
            self::assertSame(1, $repository->countFiltered(['upstreamId' => '4bf92f3577b34da6a3ce929d0e0e4736']));
            self::assertSame(0, $repository->countFiltered(['upstreamId' => '4BF92F3577B34DA6A3CE929D0E0E4736']));
        } finally {
            $this->drop($db);
        }
    }

    private function drop(UsageTestDatabase $db): void
    {
        $db->connection()->close();
    }
}
