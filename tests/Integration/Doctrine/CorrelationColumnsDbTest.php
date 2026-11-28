<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Doctrine;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Doctrine\CorrelationColumnsListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use Nowo\HttpLogBundle\Tests\Support\CollectingLogger;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

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

    /**
     * One indexed lookup on `(correlation_id, created_at)` returns everything of a request: the web row (written by the
     * recorder, its `correlation_id` = its `request_id`), a named inbound channel row and the outbound / mailer rows.
     */
    #[Test]
    #[DataProvider('servers')]
    public function webAndNamedChannelRowsCarryTheRequestIdAsCorrelationId(string $source): void
    {
        $db = RollupTestPlatforms::open($source, correlation: true);

        try {
            $config   = ['request_headers' => false, 'response_headers' => false, 'request_body' => false, 'client_ip' => false, 'user' => false, 'max_body_bytes' => 1024];
            $recorder = new HttpLogRecorder($db->entityManager, $this->createStub(MessageBusInterface::class), new MockClock(), new CollectingLogger(), new HttpLogRedactor([], [], []), new CapturePolicy($config), new ContentTypeClassifier(), false, $config, managerRegistry: $db);
            $request  = Request::create('/orders');
            $recorder->record($request, new Response('ok'), 1.0, 'beacon-req-7', '4bf92f3577b34da6a3ce929d0e0e4736');

            $writer = new UsageEntryWriter($db);
            self::assertTrue($writer->write(new UsageCapture('api', new DateTimeImmutable(), 'GET', '/api/x', correlationId: 'beacon-req-7')));
            self::assertTrue($writer->write(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', '/v2', provider: 'clickup', correlationId: 'beacon-req-7')));
            self::assertTrue($writer->write(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', '/v2', provider: 'clickup', correlationId: 'other')));

            $web = $db->connection()->fetchAssociative('SELECT request_id, correlation_id, upstream_id FROM ' . $db->table() . ' WHERE channel IS NULL');
            self::assertSame(['request_id' => 'beacon-req-7', 'correlation_id' => 'beacon-req-7', 'upstream_id' => '4bf92f3577b34da6a3ce929d0e0e4736'], $web);
            self::assertSame(3, (int) $db->connection()->fetchOne('SELECT COUNT(*) FROM ' . $db->table() . ' WHERE correlation_id = ?', ['beacon-req-7']), 'web + named channel + outbound in one lookup');
            self::assertSame(3, $db->repository()->countFiltered(['correlationId' => 'beacon-req-7']));
        } finally {
            $this->drop($db);
        }
    }

    private function drop(UsageTestDatabase $db): void
    {
        $db->connection()->close();
    }
}
