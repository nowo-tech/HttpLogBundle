<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Rollup;

use DateTimeImmutable;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\HttpLogBundle\Rollup\RollupBucket;
use Nowo\HttpLogBundle\Rollup\RollupTables;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function array_filter;
use function array_map;
use function array_values;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function str_starts_with;
use function strcmp;
use function substr;
use function usort;

/**
 * HL-1 §9.3–§9.5 on SQLite, MySQL / MariaDB and PostgreSQL: exactly-once counting under redelivery, late events on
 * their own (UTC) day, the cutoff, the ledger, purge, the credential fold and tenant erasure.
 */
final class RollupStoreTest extends TestCase
{
    private UsageTestDatabase $db;

    private MockClock $clock;

    private UsageRollupStore $store;

    private UsageEntryWriter $writer;

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->connection()->close();
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function platforms(): iterable
    {
        return RollupTestPlatforms::platforms();
    }

    private function open(string $source, string $now = '2026-11-08T10:00:00+00:00', ?int $retentionMonths = 13): void
    {
        $this->db     = RollupTestPlatforms::open($source);
        $this->clock  = new MockClock(new DateTimeImmutable($now));
        $this->store  = new UsageRollupStore($this->db, $this->db->rollupTables, $this->clock, $retentionMonths, 7, ['api' => 500]);
        $this->writer = new UsageEntryWriter($this->db, $this->store);
    }

    private function capture(string $at, bool $row = true, ?int $status = 200, string $channel = 'outbound', ?string $credential = null, ?string $tenant = 'org-a', float $duration = 120.0, ?string $eventId = null): UsageCapture
    {
        return new UsageCapture(
            channel: $channel,
            createdAt: new DateTimeImmutable($at),
            method: 'GET',
            path: '/v1/{seg}',
            provider: $channel === 'outbound' ? 'clickup' : null,
            host: 'api.example.test',
            statusCode: $status,
            durationMs: $duration,
            responseBytes: 10,
            retryCount: 1,
            tenantKey: $tenant,
            eventId: $eventId,
            credentialKind: $credential === null ? null : 'personal_token',
            credentialId: $credential,
            row: $row,
            rollup: true,
        );
    }

    /** @return list<array<string, mixed>> */
    private function buckets(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->connection()->fetchAllAssociative('SELECT * FROM ' . $this->db->rollupTables->daily . ' ORDER BY day, channel, credential_id, tenant_key');

        return $rows;
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->db->connection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    #[Test]
    #[DataProvider('platforms')]
    public function aRedeliveredMessageIsCountedOnceWithOrWithoutARow(string $source): void
    {
        $this->open($source);
        $row       = $this->capture('2026-11-08T09:00:00+00:00');
        $countOnly = $this->capture('2026-11-08T09:30:00+00:00', row: false, duration: 3000.0);

        self::assertTrue($this->writer->write($row));
        self::assertTrue($this->writer->write($countOnly));
        // redelivery of both (and a crash between commit and ack is the same: the next attempt is a redelivery)
        self::assertFalse($this->writer->write(UsageCapture::fromArray($row->toArray())));
        self::assertFalse($this->writer->write(UsageCapture::fromArray($countOnly->toArray())));

        $buckets = $this->buckets();
        self::assertCount(1, $buckets);
        self::assertSame(2, (int) $buckets[0]['calls']);
        self::assertSame(1, (int) $buckets[0]['rows_stored']);
        self::assertSame(2, (int) $buckets[0]['retries']);
        self::assertSame(20, (int) $buckets[0]['bytes']);
        self::assertSame(2, (int) $buckets[0]['status_2xx']);
        self::assertSame(1, (int) $buckets[0]['d_le_250']);
        self::assertSame(1, (int) $buckets[0]['d_le_5000']);
        self::assertSame(3000, (int) $buckets[0]['duration_ms_max']);
        self::assertSame(3120, (int) $buckets[0]['duration_ms_sum']);
        self::assertSame('2026-11-08 09:00:00', substr((string) $buckets[0]['first_seen_at'], 0, 19));
        self::assertSame('2026-11-08 09:30:00', substr((string) $buckets[0]['last_seen_at'], 0, 19));
        self::assertSame(1, $this->rowsIn($this->db->table()));
        self::assertSame(1, $this->rowsIn($this->db->rollupTables->eventSeen));
    }

    #[Test]
    #[DataProvider('platforms')]
    public function aLateEventLandsOnItsOwnUtcDayWhateverTheTimeZone(string $source): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');

        try {
            $this->open($source, '2026-11-09T00:05:00+00:00');
            // consumed after midnight, captured the day before; local (+13:00) dates differ from the UTC ones
            self::assertTrue($this->writer->write($this->capture('2026-11-08T23:59:00+00:00', row: false)));
            self::assertTrue($this->writer->write($this->capture('2026-11-09T12:30:00+13:00', row: false)));
        } finally {
            date_default_timezone_set($zone);
        }

        $buckets = $this->buckets();
        self::assertSame(['2026-11-08', '2026-11-08'], [substr((string) $buckets[0]['day'], 0, 10), substr((string) $buckets[0]['first_seen_at'], 0, 10)]);
        self::assertSame(2, (int) $buckets[0]['calls']);
        self::assertSame('2026-11-08 23:59:00', substr((string) $buckets[0]['last_seen_at'], 0, 19));
    }

    #[Test]
    #[DataProvider('platforms')]
    public function anEventOlderThanTheCutoffIsNotCountedButItsRowIsKept(string $source): void
    {
        $this->open($source);
        self::assertSame('2025-11-01', $this->store->cutoffDay());

        self::assertTrue($this->writer->write($this->capture('2025-10-31T23:00:00+00:00')));
        self::assertFalse($this->writer->write($this->capture('2025-10-31T23:00:00+00:00', row: false)));
        $old = $this->capture('2025-10-30T10:00:00+00:00');
        self::assertTrue($this->writer->write($old));
        self::assertFalse($this->writer->write($old), 'redelivery of an old row');
        self::assertTrue($this->writer->write($this->capture('2025-11-01T00:00:00+00:00', row: false)));

        self::assertSame(2, $this->rowsIn($this->db->table()));
        self::assertCount(1, $this->buckets());
        self::assertSame('2025-11-01', substr((string) $this->buckets()[0]['day'], 0, 10));
    }

    #[Test]
    #[DataProvider('platforms')]
    public function errorsFollowTheChannelThresholdAndTransportErrorsHaveNoStatus(string $source): void
    {
        $this->open($source);
        $this->writer->write($this->capture('2026-11-08T09:00:00+00:00', status: 404, channel: 'api', tenant: null));
        $this->writer->write($this->capture('2026-11-08T09:00:00+00:00', status: 503, channel: 'api', tenant: null));
        $this->writer->write($this->capture('2026-11-08T09:00:00+00:00', status: 404, tenant: null));
        $this->writer->write(new UsageCapture(channel: 'outbound', createdAt: new DateTimeImmutable('2026-11-08T09:00:00+00:00'), method: 'GET', provider: 'clickup', errorKind: 'timeout', durationMs: 20000.0, row: false, rollup: true));

        $buckets = $this->buckets();
        self::assertSame(['api', 'outbound'], array_map(static fn (array $b): string => (string) $b['channel'], $buckets));
        // api: error_status_min 500, so only the 503 is an error
        self::assertSame([2, 1, 0, 1, 1], [(int) $buckets[0]['calls'], (int) $buckets[0]['errors'], (int) $buckets[0]['transport_errors'], (int) $buckets[0]['status_4xx'], (int) $buckets[0]['status_5xx']]);
        self::assertSame('', (string) $buckets[0]['provider']);
        self::assertSame('', (string) $buckets[0]['tenant_key']);
        // outbound: the 404 and the timeout (no status) are both errors, the timeout a transport error
        self::assertSame([2, 2, 1, 1, 1, 20000], [(int) $buckets[1]['calls'], (int) $buckets[1]['errors'], (int) $buckets[1]['transport_errors'], (int) $buckets[1]['status_4xx'], (int) $buckets[1]['d_gt_10000'], (int) $buckets[1]['duration_ms_max']]);
    }

    #[Test]
    #[DataProvider('platforms')]
    public function aFailedWriteRollsBackTheRowAndTheLedger(string $source): void
    {
        $this->open($source);
        $writer = new UsageEntryWriter($this->db, new UsageRollupStore($this->db, new RollupTables('missing_daily', $this->db->rollupTables->eventSeen), $this->clock));

        try {
            $writer->write($this->capture('2026-11-08T09:00:00+00:00', row: false));
            self::fail('the upsert on a missing table must fail');
        } catch (DbalException $exception) {
            self::assertNotInstanceOf(UniqueConstraintViolationException::class, $exception);
        }
        self::assertSame(0, $this->rowsIn($this->db->rollupTables->eventSeen));
        self::assertFalse($this->db->connection()->isTransactionActive());
    }

    #[Test]
    #[DataProvider('platforms')]
    public function purgeKeepsThirteenCalendarMonthsAndTheLedgerForDedupeDays(string $source): void
    {
        $this->open($source);
        foreach (['2025-11-01T00:00:00+00:00', '2026-10-31T12:00:00+00:00', '2026-11-08T08:00:00+00:00'] as $at) {
            $this->writer->write($this->capture($at, row: false));
        }
        // a bucket of last October, written before the cutoff moved on
        $this->store->upsert($this->db->connection(), RollupBucket::fromCapture($this->capture('2025-10-31T12:00:00+00:00', row: false)));

        self::assertSame(1, $this->store->purgeBuckets(dryRun: true));
        self::assertSame(1, $this->store->purgeBuckets(batchSize: 1));
        self::assertSame(['2025-11-01', '2026-10-31', '2026-11-08'], array_map(static fn (array $b): string => substr((string) $b['day'], 0, 10), $this->buckets()));
        self::assertSame(2, $this->store->purgeBuckets(days: 7, batchSize: 1));

        // ledger: ids older than 7 days go
        self::assertSame(2, $this->store->purgeLedger(dryRun: true));
        self::assertSame(2, $this->store->purgeLedger(batchSize: 1));
        self::assertSame(1, $this->rowsIn($this->db->rollupTables->eventSeen));
        self::assertSame(0, $this->store->purgeLedger(days: 0), 'today is never older than today');
        $this->clock->modify('+1 day');
        self::assertSame(1, $this->store->purgeLedger(days: 0));

        $forever = new UsageRollupStore($this->db, $this->db->rollupTables, $this->clock, null);
        self::assertNull($forever->cutoffDay());
        self::assertSame(0, $forever->purgeBuckets());
    }

    #[Test]
    public function negativeDaysAreRejected(): void
    {
        $this->open('sqlite');

        $this->expectException(InvalidArgumentException::class);
        $this->store->purgeLedger(days: -1);
    }

    #[Test]
    #[DataProvider('platforms')]
    public function anErasedCredentialFoldsIntoTildeErasedAndKeepsTheTotals(string $source): void
    {
        $this->open($source);
        foreach (['tok-1', 'tok-1', 'tok-2', 'TOK-1'] as $i => $credential) {
            $this->writer->write($this->capture('2026-11-08T0' . $i . ':00:00+00:00', row: false, credential: $credential, duration: 50.0 + $i));
        }
        $this->writer->write($this->capture('2026-11-07T09:00:00+00:00', row: false, credential: 'tok-1'));

        self::assertSame(2, $this->store->foldCredential('personal_token', 'tok-1'));
        self::assertSame(0, $this->store->foldCredential('personal_token', 'tok-1'));
        // fold a second credential into the same erased bucket
        self::assertSame(1, $this->store->foldCredential('personal_token', 'tok-2'));

        $byKey = [];
        foreach ($this->buckets() as $bucket) {
            $byKey[substr((string) $bucket['day'], 0, 10) . '/' . $bucket['credential_id']] = (int) $bucket['calls'];
        }
        self::assertSame(['2026-11-07/~erased' => 1, '2026-11-08/TOK-1' => 1, '2026-11-08/~erased' => 3], $byKey);
        $erased = array_values(array_filter($this->buckets(), static fn (array $b): bool => $b['credential_id'] === '~erased' && str_starts_with((string) $b['day'], '2026-11-08')))[0];
        self::assertSame('2026-11-08 00:00:00', substr((string) $erased['first_seen_at'], 0, 19));
        self::assertSame('2026-11-08 02:00:00', substr((string) $erased['last_seen_at'], 0, 19));
        self::assertSame(52, (int) $erased['duration_ms_max']);
    }

    #[Test]
    #[DataProvider('platforms')]
    public function tenantErasureDeletesOnlyThatTenantsBuckets(string $source): void
    {
        $this->open($source);
        foreach (['org-a', 'org-b', 'ORG-A', null] as $tenant) {
            $this->writer->write($this->capture('2026-11-08T09:00:00+00:00', row: false, tenant: $tenant));
        }

        self::assertSame(1, $this->store->deleteTenant('org-a'));
        self::assertSame(['', 'ORG-A', 'org-b'], array_map(static fn (array $b): string => (string) $b['tenant_key'], $this->sortedBy('tenant_key')));
    }

    #[Test]
    #[DataProvider('platforms')]
    public function hasBucketsAnswersPerDayAndChannel(string $source): void
    {
        $this->open($source);
        $this->writer->write($this->capture('2026-11-08T09:00:00+00:00', row: false));

        self::assertTrue($this->store->hasBuckets('2026-11-08', 'outbound'));
        self::assertFalse($this->store->hasBuckets('2026-11-08', 'api'));
        self::assertFalse($this->store->hasBuckets('2026-11-07', 'outbound'));
    }

    #[Test]
    public function withoutTheRollupStoreACountOnlyEventWritesNothing(): void
    {
        $this->open('sqlite');
        $writer = new UsageEntryWriter($this->db);

        self::assertFalse($writer->write($this->capture('2026-11-08T09:00:00+00:00', row: false)));
        self::assertTrue($writer->write($this->capture('2026-11-08T09:00:00+00:00')));
        self::assertSame([], $this->buckets());
        self::assertSame(1, $this->rowsIn($this->db->table()));
    }

    #[Test]
    public function theStoreNeedsAnEntityManagerForTheEntry(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $store    = new UsageRollupStore($registry, new RollupTables(), new MockClock());

        $this->expectException(LogicException::class);
        $store->connection();
    }

    /** @return list<array<string, mixed>> */
    private function sortedBy(string $column): array
    {
        $rows = $this->buckets();
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a[$column], (string) $b[$column]));

        return $rows;
    }
}
