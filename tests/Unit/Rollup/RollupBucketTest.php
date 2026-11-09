<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Rollup;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Rollup\RollupBucket;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * HL-1 §9.1 / §9.2: one event's counters, the duration histogram and UTC days; summing events of one key.
 */
final class RollupBucketTest extends TestCase
{
    #[Test]
    public function oneEventFillsItsCountersAndTheHistogram(): void
    {
        $bucket = RollupBucket::fromValues(new DateTimeImmutable('2026-11-09T00:30:00+02:00'), 'outbound', 'clickup', 'personal_token', '7', 'org-a', 302, null, 2, 512, 250.4, false);

        self::assertSame('2026-11-08', $bucket['day'], 'UTC day');
        self::assertSame('2026-11-08 22:30:00', $bucket['first_seen_at']);
        self::assertSame([1, 0, 0, 1, 2, 512, 0, 250, 250], [$bucket['calls'], $bucket['errors'], $bucket['transport_errors'], $bucket['status_3xx'], $bucket['retries'], $bucket['bytes'], $bucket['rows_stored'], $bucket['duration_ms_sum'], $bucket['duration_ms_max']]);
        self::assertSame(1, $bucket['d_le_500'], '250.4 ms is above 250');

        $empty = RollupBucket::fromValues(new DateTimeImmutable('2026-11-08T10:00:00+00:00'), 'mailer', null, null, null, null, null, 'transport', null, null, null, true);
        self::assertSame(['', '', '', ''], [$empty['provider'], $empty['credential_kind'], $empty['credential_id'], $empty['tenant_key']]);
        self::assertSame([1, 1, 0, 0], [$empty['errors'], $empty['transport_errors'], $empty['duration_ms_sum'], $empty['duration_ms_max']]);
        self::assertSame(1, $empty['rows_stored']);
        self::assertSame('d_le_50', RollupBucket::histogramColumn(50.0));
        self::assertSame('d_gt_10000', RollupBucket::histogramColumn(10000.5));
    }

    #[Test]
    public function addSumsCountersAndKeepsTheExtremes(): void
    {
        $a = RollupBucket::fromValues(new DateTimeImmutable('2026-11-08T10:00:00+00:00'), 'api', null, null, null, null, 200, null, null, 10, 40.0, true);
        $b = RollupBucket::fromValues(new DateTimeImmutable('2026-11-08T09:00:00+00:00'), 'api', null, null, null, null, 500, null, null, 5, 900.0, true);

        $sum = RollupBucket::add($a, $b);
        self::assertSame([2, 1, 15, 900, 940, '2026-11-08 09:00:00', '2026-11-08 10:00:00'], [$sum['calls'], $sum['errors'], $sum['bytes'], $sum['duration_ms_max'], $sum['duration_ms_sum'], $sum['first_seen_at'], $sum['last_seen_at']]);
    }
}
