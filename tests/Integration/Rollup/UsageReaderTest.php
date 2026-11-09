<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Rollup;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Nowo\HttpLogBundle\Tests\Support\RollupTestPlatforms;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\CallQuery;
use Nowo\HttpLogBundle\Usage\DailyUsage;
use Nowo\HttpLogBundle\Usage\HttpLogUsageReader;
use Nowo\HttpLogBundle\Usage\UsageCall;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use Nowo\HttpLogBundle\Usage\UsageQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function array_keys;
use function array_map;
use function iterator_to_array;
use function strlen;

/**
 * HL-1 §11.1 read API on SQLite, MySQL / MariaDB and PostgreSQL: explicit tenant scope (two tenants and a call without
 * one), grouping, the p95 upper bound, last use of credentials, keyset pages of per-call rows.
 */
final class UsageReaderTest extends TestCase
{
    private UsageTestDatabase $db;

    private HttpLogUsageReader $reader;

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

    private function seed(string $source): void
    {
        $this->db     = RollupTestPlatforms::open($source);
        $store        = new UsageRollupStore($this->db, $this->db->rollupTables, new MockClock(new DateTimeImmutable('2026-11-08T12:00:00+00:00')));
        $writer       = new UsageEntryWriter($this->db, $store);
        $this->reader = new HttpLogUsageReader($this->db, $store);

        $events = [
            // tenant, credential, day, status, ms, channel, row
            ['org-a', 'tok-1', '2026-11-08T09:00:00+00:00', 200, 40.0, 'api', true],
            ['org-a', 'tok-1', '2026-11-08T09:01:00+00:00', 200, 90.0, 'api', false],
            ['org-a', 'tok-1', '2026-11-07T09:00:00+00:00', 429, 30.0, 'api', true],
            ['org-a', 'tok-2', '2026-10-01T09:00:00+00:00', 200, 700.0, 'api', false],
            ['org-a', null, '2026-11-08T10:00:00+00:00', 503, 12000.0, 'outbound', true],
            ['ORG-A', 'tok-1', '2026-11-08T09:00:00+00:00', 200, 40.0, 'api', true],
            ['org-b', 'tok-9', '2026-11-08T09:00:00+00:00', 200, 40.0, 'api', true],
            [null, null, '2026-11-08T09:00:00+00:00', 200, 40.0, 'api', true],
        ];
        foreach ($events as [$tenant, $credential, $at, $status, $ms, $channel, $row]) {
            $writer->write(new UsageCapture(
                channel: $channel,
                createdAt: new DateTimeImmutable($at),
                method: 'GET',
                path: '/api/tasks/{id}',
                provider: $channel === 'outbound' ? 'clickup' : null,
                statusCode: $status,
                durationMs: $ms,
                tenantKey: $tenant,
                credentialKind: $credential === null ? null : 'personal_token',
                credentialId: $credential,
                row: $row,
                rollup: true,
            ));
        }
    }

    #[Test]
    #[DataProvider('platforms')]
    public function aTenantQueryNeverReturnsAnotherTenant(string $source): void
    {
        $this->seed($source);

        $total = $this->reader->daily(UsageQuery::forTenant('org-a')->groupBy());
        self::assertCount(1, $total);
        self::assertSame(5, $total[0]->calls);
        self::assertNull($total[0]->tenantKey);

        $byTenant = $this->reader->daily(UsageQuery::forTenant('org-a')->groupBy('tenant_key'));
        self::assertSame(['org-a'], array_map(static fn (DailyUsage $d): ?string => $d->tenantKey, $byTenant));
        self::assertSame(['ORG-A'], array_map(static fn (DailyUsage $d): ?string => $d->tenantKey, $this->reader->daily(UsageQuery::forTenant('ORG-A')->groupBy('tenant_key'))));
        self::assertSame([], $this->reader->daily(UsageQuery::forTenant('org-c')));

        $platform = $this->reader->daily(UsageQuery::platform()->groupBy('tenant_key'));
        self::assertSame([null, 'ORG-A', 'org-a', 'org-b'], array_map(static fn (DailyUsage $d): ?string => $d->tenantKey, $platform));
        self::assertSame(1, $platform[0]->calls, 'the call without a tenant is a platform bucket only');
    }

    #[Test]
    #[DataProvider('platforms')]
    public function dailyGroupsFiltersAndGivesTheP95UpperBound(string $source): void
    {
        $this->seed($source);

        $days = $this->reader->daily(UsageQuery::forTenant('org-a')->withChannels('api')->between(new DateTimeImmutable('2026-11-07'), new DateTimeImmutable('2026-11-08 23:00')));
        self::assertSame(['2026-11-07', '2026-11-08'], array_map(static fn (DailyUsage $d): string => (string) $d->day?->format('Y-m-d'), $days));
        self::assertSame('UTC', $days[0]->day?->getTimezone()->getName());
        self::assertSame([1, 1, 0], [$days[0]->calls, $days[0]->errors, $days[0]->status2xx]);
        self::assertSame([2, 0, 2, 1], [$days[1]->calls, $days[1]->errors, $days[1]->status2xx, $days[1]->rowsStored]);
        self::assertSame(100, $days[1]->p95UpperBoundMs());
        self::assertSame(65.0, $days[1]->averageMs());
        self::assertSame(['le_50' => 1, 'le_100' => 1, 'le_250' => 0, 'le_500' => 0, 'le_1000' => 0, 'le_2500' => 0, 'le_5000' => 0, 'le_10000' => 0, 'gt_10000' => 0], $days[1]->histogram);
        self::assertSame('2026-11-08 09:01:00', $days[1]->lastSeenAt?->format('Y-m-d H:i:s'));

        $slow = $this->reader->daily(UsageQuery::forTenant('org-a')->withProviders('clickup')->groupBy('provider', 'channel'));
        self::assertSame(['clickup', 'outbound', 12000, 1, 1], [$slow[0]->provider, $slow[0]->channel, $slow[0]->p95UpperBoundMs(), $slow[0]->errors, $slow[0]->status5xx]);

        $byCredential = $this->reader->daily(UsageQuery::forTenant('org-a')->withCredential('personal_token')->groupBy('credential_kind', 'credential_id'));
        self::assertSame(['tok-1' => 3, 'tok-2' => 1], [$byCredential[0]->credentialId => $byCredential[0]->calls, $byCredential[1]->credentialId => $byCredential[1]->calls]);
        self::assertSame('personal_token', $byCredential[0]->credentialKind);
        self::assertCount(1, $this->reader->daily(UsageQuery::forTenant('org-a')->withCredential('personal_token', 'tok-2')->groupBy('credential_id')));

        $streamed = iterator_to_array($this->reader->iterateDaily(UsageQuery::platform()->groupBy('day')), false);
        self::assertCount(3, $streamed);
        self::assertSame(8, $streamed[0]->calls + $streamed[1]->calls + $streamed[2]->calls);
    }

    #[Test]
    #[DataProvider('platforms')]
    public function lastUsedComesFromTheBucketsOfTheScope(string $source): void
    {
        $this->seed($source);

        $usage = $this->reader->lastUsed(UsageQuery::forTenant('org-a'), 'personal_token', ['tok-1', 'tok-2', 'tok-9', 'TOK-1']);
        self::assertSame(['tok-1', 'tok-2'], array_keys($usage));
        self::assertSame('2026-11-08 09:01:00', $usage['tok-1']->lastSeenAt->format('Y-m-d H:i:s'));
        self::assertSame([3, 1], [$usage['tok-1']->calls30d, $usage['tok-1']->errors30d]);
        // 2026-10-01 is outside the 30 days, still the last use
        self::assertSame([0, 0, '2026-10-01'], [$usage['tok-2']->calls30d, $usage['tok-2']->errors30d, $usage['tok-2']->lastSeenAt->format('Y-m-d')]);
        self::assertSame(['tok-9'], array_keys($this->reader->lastUsed(UsageQuery::platform()->withChannels('api'), 'personal_token', ['tok-9'])));
        self::assertSame([], $this->reader->lastUsed(UsageQuery::platform(), 'personal_token', []));
        self::assertSame([], $this->reader->lastUsed(UsageQuery::platform()->withProviders('stripe'), 'personal_token', ['tok-1']));
    }

    #[Test]
    #[DataProvider('platforms')]
    public function callsArePagedNewestFirstWithinTheScope(string $source): void
    {
        $this->seed($source);

        $page = $this->reader->calls(CallQuery::forTenant('org-a'), null, 2);
        self::assertSame(['outbound', 'api'], array_map(static fn (UsageCall $c): string => $c->channel, $page->items));
        self::assertNotNull($page->nextCursor);
        $next = $this->reader->calls(CallQuery::forTenant('org-a'), $page->nextCursor, 2);
        self::assertCount(1, $next->items);
        self::assertNull($next->nextCursor);
        self::assertSame([429, 200], [$page->items[1]->statusCode, $next->items[0]->statusCode]);

        $call = $page->items[0];
        self::assertSame(['clickup', 'GET', '/api/tasks/{id}', 503, 'org-a'], [$call->provider, $call->method, $call->path, $call->statusCode, $call->tenantKey]);
        self::assertSame(32, strlen((string) $call->eventId));

        self::assertCount(1, $this->reader->calls(CallQuery::forTenant('org-a')->withStatusClass(5))->items);
        self::assertCount(1, $this->reader->calls(CallQuery::forTenant('org-a')->withChannels('api')->withStatusClass(4))->items);
        self::assertCount(2, $this->reader->calls(CallQuery::forTenant('org-a')->withCredential('personal_token', 'tok-1'))->items);
        self::assertCount(1, $this->reader->calls(CallQuery::forTenant('org-a')->withProviders('clickup'))->items);
        self::assertCount(0, $this->reader->calls(CallQuery::forTenant('org-a')->withErrorKind('timeout'))->items);
        self::assertCount(1, $this->reader->calls(CallQuery::forTenant('org-a')->between(new DateTimeImmutable('2026-11-08 09:30:00+00:00'), new DateTimeImmutable('2026-11-08 23:00:00+00:00')))->items);
        self::assertCount(1, $this->reader->calls(CallQuery::forTenant('ORG-A'))->items);
        self::assertCount(6, $this->reader->calls(CallQuery::platform())->items);
    }

    #[Test]
    public function callsRejectBadInput(): void
    {
        $this->seed('sqlite');

        foreach ([[null, 0], [null, 201], ['0', 10], ['abc', 10], ["1\n", 10]] as [$cursor, $limit]) {
            try {
                $this->reader->calls(CallQuery::platform(), $cursor, $limit);
                self::fail('rejected: ' . $cursor . '/' . $limit);
            } catch (InvalidArgumentException) {
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->reader->calls(CallQuery::platform()->withChannels('web'));
    }

    #[Test]
    public function withoutTheRollupTheDailyQueriesFailAndCallsNeedTheColumns(): void
    {
        $reader = new HttpLogUsageReader(new UsageTestDatabase());
        self::assertSame([], $reader->calls(CallQuery::platform())->items);

        try {
            $reader->daily(UsageQuery::platform());
            self::fail('no rollup');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        (new HttpLogUsageReader(new UsageTestDatabase(false)))->calls(CallQuery::platform());
    }

    #[Test]
    public function callsNeedAnEntityManager(): void
    {
        $this->expectException(LogicException::class);
        (new HttpLogUsageReader($this->createStub(ManagerRegistry::class)))->calls(CallQuery::platform());
    }
}
