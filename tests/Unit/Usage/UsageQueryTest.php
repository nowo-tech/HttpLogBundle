<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Usage;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Usage\CallQuery;
use Nowo\HttpLogBundle\Usage\DailyUsage;
use Nowo\HttpLogBundle\Usage\UsageQuery;
use Nowo\HttpLogBundle\Usage\UsageScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * HL-1 §11.1: the tenant scope is explicit and validated; queries are immutable; p95 needs timed calls.
 */
final class UsageQueryTest extends TestCase
{
    #[Test]
    public function theScopeIsATenantOrThePlatform(): void
    {
        self::assertTrue(UsageScope::platform()->isPlatform());
        self::assertFalse(UsageScope::forTenant('org-a')->isPlatform());
        foreach (['', 'org a', "org\0", 'org-' . str_repeat('x', 64)] as $invalid) {
            try {
                UsageQuery::forTenant($invalid);
                self::fail('rejected: ' . $invalid);
            } catch (InvalidArgumentException) {
            }
        }

        $this->expectException(InvalidArgumentException::class);
        CallQuery::forTenant('');
    }

    #[Test]
    public function queriesAreImmutableAndValidateTheirFilters(): void
    {
        $base  = UsageQuery::platform();
        $query = $base->withChannels('api')->withProviders('clickup')->withCredential('personal_token', 'pt-1')->between(new DateTimeImmutable('2026-11-08T23:30:00-02:00'), new DateTimeImmutable('2026-11-09'))->groupBy('day', 'tenant_key');
        self::assertSame(['day'], $base->groupBy);
        self::assertSame([['api'], ['clickup'], 'personal_token', ['pt-1'], '2026-11-09', '2026-11-09', ['day', 'tenant_key']], [$query->channels, $query->providers, $query->credentialKind, $query->credentialIds, $query->fromDay, $query->toDay, $query->groupBy]);

        $calls = CallQuery::platform()->withChannels('api')->withProviders('x')->withCredential('k', 'a', 'b')->between(null, new DateTimeImmutable('2026-11-09'))->withStatusClass(4)->withErrorKind('exception');
        self::assertSame([['api'], ['x'], 'k', ['a', 'b'], null, 4, 'exception'], [$calls->channels, $calls->providers, $calls->credentialKind, $calls->credentialIds, $calls->from, $calls->statusClass, $calls->errorKind]);
        self::assertSame('2026-11-09', $calls->to?->format('Y-m-d'));
        self::assertNotNull(CallQuery::platform()->between(new DateTimeImmutable(), null)->from);

        try {
            CallQuery::platform()->withStatusClass(6);
            self::fail('status class 6');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        UsageQuery::platform()->groupBy('day', 'user_identifier');
    }

    #[Test]
    public function withoutTimedCallsThereIsNoPercentileOrMean(): void
    {
        $usage = new DailyUsage(null, null, null, null, null, null, 1, 1, 1, 0, 0, 0, 0, 0, 0, 1, 0, 0, ['le_50' => 0], null, null);

        self::assertNull($usage->p95UpperBoundMs());
        self::assertNull($usage->averageMs());
    }
}
