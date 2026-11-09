<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Rollup;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\HttpLogBundle\Command\PurgeHttpLogCommand;
use Nowo\HttpLogBundle\Command\RollupBackfillCommand;
use Nowo\HttpLogBundle\Privacy\HttpLogUsageEraser;
use Nowo\HttpLogBundle\Rollup\RollupBucket;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function array_map;
use function array_values;
use function date_default_timezone_get;
use function date_default_timezone_set;
use function substr;

/**
 * HL-1 S6 on SQLite, MySQL / MariaDB and PostgreSQL: purge scopes of named channels, the rollup and the ledger;
 * credential and tenant erasure; the backfill of days without buckets.
 */
final class RetentionErasureTest extends TestCase
{
    private UsageTestDatabase $db;

    private MockClock $clock;

    private UsageRollupStore $store;

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

    private function open(string $source): void
    {
        $this->db    = RollupTestPlatforms::open($source);
        $this->clock = new MockClock(new DateTimeImmutable('2026-11-08T12:00:00+00:00'));
        $this->store = new UsageRollupStore($this->db, $this->db->rollupTables, $this->clock, 13, 7, ['api' => 500]);
    }

    private function write(string $channel, string $at, ?int $status = 200, ?string $credential = null, ?string $tenant = 'org-a', bool $rollup = true, bool $row = true, ?string $provider = null, ?float $ms = 30.0): void
    {
        (new UsageEntryWriter($this->db, $this->store))->write(new UsageCapture(
            channel: $channel,
            createdAt: new DateTimeImmutable($at),
            method: 'GET',
            path: '/x',
            provider: $provider,
            statusCode: $status,
            durationMs: $ms,
            tenantKey: $tenant,
            credentialKind: $credential === null ? null : 'personal_token',
            credentialId: $credential,
            row: $row,
            rollup: $rollup,
        ));
    }

    /** @return list<string> "day channel credential calls" */
    private function buckets(): array
    {
        $rows = $this->db->connection()->fetchAllAssociative('SELECT day, channel, credential_id, tenant_key, calls FROM ' . $this->db->rollupTables->daily . ' ORDER BY day, channel, credential_id, tenant_key');

        return array_map(static fn (array $r): string => substr((string) $r['day'], 0, 10) . ' ' . $r['channel'] . ' ' . $r['credential_id'] . ' ' . $r['tenant_key'] . ' ' . $r['calls'], $rows);
    }

    private function rows(): int
    {
        return (int) $this->db->connection()->fetchOne('SELECT COUNT(*) FROM ' . $this->db->table());
    }

    #[Test]
    #[DataProvider('platforms')]
    public function purgeRunsEveryScopeWithItsOwnCutoff(string $source): void
    {
        $this->open($source);
        $this->write('api', '2026-10-01T10:00:00+00:00');
        $this->write('api', '2026-11-07T10:00:00+00:00');
        $this->write('webhooks', '2026-10-01T10:00:00+00:00');
        $this->write('api', '2026-11-07T11:00:00+00:00', row: false);
        $this->write('api', '2026-10-25T11:00:00+00:00', row: false);
        $this->store->upsert($this->db->connection(), RollupBucket::fromValues(new DateTimeImmutable('2025-10-31T10:00:00+00:00'), 'api', null, null, null, null, 200, null, null, null, 1.0, false));

        $service = new PurgeHttpLogService($this->db->repository(), $this->clock, ['days' => 30, 'purge_batch_size' => 1], ['api' => 30, 'webhooks' => null], $this->store);
        self::assertSame(['web', 'api', 'webhooks', 'rollup', 'ledger'], $service->scopes());
        self::assertSame(['web', 'api', 'webhooks'], $service->entryScopes());

        self::assertSame(['web' => 0, 'api' => 1, 'webhooks' => 0, 'rollup' => 1, 'ledger' => 1], $service->purgeScopes([], null, true));
        self::assertSame(1, $service->purgeEntriesByRetention(true));
        self::assertSame(3, $service->purgeByRetention());
        self::assertSame(2, $this->rows(), 'webhooks rows are kept forever');
        self::assertSame(['rollup' => 3], $service->purgeScopes(['rollup'], 10, true), 'with --days: buckets older than 10 days');
        self::assertSame(2, $service->purgeByRetention(0, true), 'a bare --days counts rows only (2), never the buckets');

        $tester = new CommandTester(new PurgeHttpLogCommand($service));
        self::assertSame(Command::SUCCESS, $tester->execute(['--channel' => ['ledger', 'rollup'], '--dry-run' => true]));
        self::assertStringContainsString('ledger: would purge 0 entries', $tester->getDisplay());
        self::assertStringContainsString('rollup: would purge 0 entries', $tester->getDisplay());
    }

    #[Test]
    #[DataProvider('platforms')]
    public function erasingACredentialDeletesItsRowsAndFoldsItsBuckets(string $source): void
    {
        $this->open($source);
        $this->write('api', '2026-11-08T09:00:00+00:00', credential: 'pt-1');
        $this->write('api', '2026-11-08T09:10:00+00:00', credential: 'pt-1', row: false);
        $this->write('api', '2026-11-08T09:20:00+00:00', credential: 'PT-1');
        $this->write('api', '2026-11-08T09:30:00+00:00', credential: 'pt-2');
        $eraser = new HttpLogUsageEraser($this->db->repository(), $this->store, ['purge_batch_size' => 1]);

        self::assertSame(1, $eraser->countByCredential('personal_token', 'pt-1'));
        self::assertSame(1, $eraser->eraseCredential('personal_token', 'pt-1'));
        self::assertSame(0, $eraser->countByCredential('personal_token', 'pt-1'));
        self::assertSame(2, $this->rows(), 'PT-1 and pt-2 stay');
        self::assertSame(['2026-11-08 api PT-1 org-a 1', '2026-11-08 api pt-2 org-a 1', '2026-11-08 api ~erased org-a 2'], $this->buckets());
    }

    #[Test]
    #[DataProvider('platforms')]
    public function erasingATenantDeletesItsRowsAndBuckets(string $source): void
    {
        $this->open($source);
        $this->write('api', '2026-11-08T09:00:00+00:00', tenant: 'org-a');
        $this->write('api', '2026-11-07T09:00:00+00:00', tenant: 'org-a', row: false);
        $this->write('api', '2026-11-08T09:00:00+00:00', tenant: 'org-b');
        $this->write('outbound', '2026-11-08T09:00:00+00:00', tenant: 'ORG-A', provider: 'clickup');

        self::assertSame(3, (new HttpLogUsageEraser($this->db->repository(), $this->store))->eraseTenant('org-a'), '1 row + 2 buckets');
        self::assertSame(2, $this->rows());
        self::assertSame(['2026-11-08 api  org-b 1', '2026-11-08 outbound  ORG-A 1'], $this->buckets());
    }

    #[Test]
    public function erasureRejectsEmptyValuesAndWorksWithoutTheRollupOrTheColumns(): void
    {
        $this->open('sqlite');
        $this->write('api', '2026-11-08T09:00:00+00:00', credential: 'pt-1', rollup: false);
        self::assertSame(1, (new HttpLogUsageEraser($this->db->repository()))->eraseCredential('personal_token', 'pt-1'));
        self::assertSame(0, (new HttpLogUsageEraser($this->db->repository()))->eraseTenant('org-a'));
        self::assertSame(0, (new HttpLogUsageEraser((new UsageTestDatabase(false))->repository()))->countByCredential('personal_token', 'pt-1'));

        foreach ([['', 'x'], ['k', ''], ['k', "a\0b"]] as [$kind, $id]) {
            try {
                (new HttpLogUsageEraser($this->db->repository()))->eraseCredential($kind, $id);
                self::fail('rejected');
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        (new HttpLogUsageEraser($this->db->repository()))->eraseTenant('');
    }

    #[Test]
    #[DataProvider('platforms')]
    public function backfillBuildsBucketsOnlyForDaysWithoutThem(string $source): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Madrid');

        try {
            $this->open($source);
            // rows logged before the rollup was enabled
            $this->write('api', '2026-11-05T23:30:00+00:00', credential: 'pt-1', rollup: false, ms: 40.0);
            $this->write('api', '2026-11-06T00:30:00+01:00', status: 503, credential: 'pt-1', rollup: false, ms: 20000.0);
            $this->write('api', '2026-11-06T10:00:00+00:00', credential: 'pt-2', rollup: false, ms: 300.0);
            $this->write('outbound', '2026-11-06T10:00:00+00:00', rollup: false, provider: 'quiet');
            $this->write('outbound', '2026-11-06T10:00:00+00:00', status: null, rollup: false, provider: 'clickup', ms: null);
            $this->write('api', '2026-11-07T10:00:00+00:00', rollup: false);
            $this->write('api', '2026-11-08T08:00:00+00:00', rollup: false);
            // a day that already has buckets (live events) is never rewritten
            $this->write('api', '2026-11-07T11:00:00+00:00', row: false);
            $this->db->connection()->insert($this->db->table(), ['method' => 'GET', 'path' => '/hello', 'status_code' => 200, 'duration_ms' => 5.0, 'request_body_truncated' => 0, 'response_body_truncated' => 0, 'response_body_stored' => 0, 'created_at' => '2026-11-06 12:00:00']);

            $command = new RollupBackfillCommand($this->store, $this->db, ['api' => true, 'outbound' => false, 'web' => false], ['quiet']);
            $tester  = new CommandTester($command);
            self::assertSame(Command::SUCCESS, $tester->execute(['--from' => '2026-11-05', '--to' => '2026-11-30', '--dry-run' => true]));
            self::assertStringContainsString('Today is counted by live events', $tester->getDisplay());
            self::assertSame(['2026-11-07 api  org-a 1'], $this->buckets(), 'dry run');

            self::assertSame(Command::SUCCESS, $tester->execute(['--from' => '2026-11-05', '--to' => '2026-11-07']));
            $display = $tester->getDisplay();
            self::assertStringContainsString('2026-11-05 api: 2 rows, 1 buckets (lower bound: sampled channel)', $display);
            self::assertStringContainsString('2026-11-06 api: 1 rows, 1 buckets', $display, 'the 00:30+01:00 row belongs to the 5th (UTC)');
            self::assertStringContainsString('2026-11-06 outbound: 1 rows, 1 buckets', $display, 'quiet has rollup: false');
            self::assertStringContainsString('2026-11-06 web: 1 rows, 1 buckets', $display);
            self::assertStringContainsString('2026-11-07 api: has buckets, skipped', $display);
            self::assertStringContainsString('Wrote 4 rollup buckets.', $display);
            self::assertSame([
                '2026-11-05 api pt-1 org-a 2',
                '2026-11-06 api pt-2 org-a 1',
                '2026-11-06 outbound  org-a 1',
                '2026-11-06 web   1',
                '2026-11-07 api  org-a 1',
            ], $this->buckets());
            $slow = $this->db->connection()->fetchAssociative('SELECT errors, d_gt_10000, rows_stored, duration_ms_max FROM ' . $this->db->rollupTables->daily . " WHERE credential_id = 'pt-1'");
            self::assertSame([1, 1, 2, 20000], array_map(intval(...), array_values((array) $slow)), 'api: error_status_min 500');

            // a second run finds buckets everywhere
            $tester->execute(['--from' => '2026-11-05', '--to' => '2026-11-06']);
            self::assertStringContainsString('Wrote 0 rollup buckets.', $tester->getDisplay());
        } finally {
            date_default_timezone_set($zone);
        }
    }

    #[Test]
    public function backfillValidatesItsDaysAndStartsAtTheCutoff(): void
    {
        $this->open('sqlite');
        $tester = new CommandTester(new RollupBackfillCommand($this->store, $this->db, ['api' => false]));

        self::assertSame(Command::INVALID, $tester->execute(['--from' => '2026-02-30']));
        self::assertSame(Command::INVALID, $tester->execute(['--to' => 'yesterday']));
        self::assertSame(Command::SUCCESS, $tester->execute(['--from' => '2020-01-01', '--to' => '2025-11-02']));
        self::assertStringContainsString('starting there', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Wrote 0 rollup buckets.', $tester->getDisplay());

        // without usage columns only web rows exist
        $db     = new UsageTestDatabase(false, rollup: true);
        $tester = new CommandTester(new RollupBackfillCommand(new UsageRollupStore($db, $db->rollupTables, $this->clock, null), $db, ['api' => false, 'web' => false]));
        $db->connection()->insert($db->table(), ['method' => 'GET', 'path' => '/hello', 'status_code' => 200, 'request_body_truncated' => 0, 'response_body_truncated' => 0, 'response_body_stored' => 0, 'created_at' => '2026-11-07 12:00:00']);
        self::assertSame(Command::SUCCESS, $tester->execute(['--to' => '2026-11-07', '--from' => '2026-11-07']));
        self::assertStringContainsString('2026-11-07 web: 1 rows, 1 buckets', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['--to' => '2026-11-07', '--from' => '2026-11-06']));
    }

    #[Test]
    public function backfillNeedsTheEntrysEntityManager(): void
    {
        $this->open('sqlite');
        $tester = new CommandTester(new RollupBackfillCommand($this->store, $this->createStub(ManagerRegistry::class), ['api' => false]));

        $this->expectException(LogicException::class);
        $tester->execute(['--from' => '2026-11-07', '--to' => '2026-11-07']);
    }
}
