<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Rollup\RollupTables;

use function array_sum;
use function ceil;
use function max;
use function substr;

/**
 * One result row of {@see HttpLogUsageReaderInterface::daily()}: the sums of the buckets of one group. A key the query
 * did not group by is null; so is a key stored empty (no provider, credential or tenant).
 */
final readonly class DailyUsage
{
    /**
     * @param array<string, int> $histogram duration buckets: `le_50`, `le_100`, `le_250`, `le_500`, `le_1000`,
     *                                      `le_2500`, `le_5000`, `le_10000`, `gt_10000` => calls
     */
    public function __construct(
        public ?DateTimeImmutable $day,
        public ?string $channel,
        public ?string $provider,
        public ?string $credentialKind,
        public ?string $credentialId,
        public ?string $tenantKey,
        public int $calls,
        public int $errors,
        public int $transportErrors,
        public int $status2xx,
        public int $status3xx,
        public int $status4xx,
        public int $status5xx,
        public int $retries,
        public int $bytes,
        public int $rowsStored,
        public int $durationMsSum,
        public int $durationMsMax,
        public array $histogram,
        public ?DateTimeImmutable $firstSeenAt,
        public ?DateTimeImmutable $lastSeenAt,
    ) {
    }

    /** Mean duration of the calls with a duration, in milliseconds. */
    public function averageMs(): ?float
    {
        $timed = array_sum($this->histogram);

        return $timed === 0 ? null : $this->durationMsSum / $timed;
    }

    /**
     * Upper bound of the histogram bucket that holds the 95th percentile (HL-1 §9.4); exact percentiles are not
     * stored. Beyond 10 000 ms it is the largest duration seen. null without timed calls.
     */
    public function p95UpperBoundMs(): ?int
    {
        $timed = array_sum($this->histogram);
        if ($timed === 0) {
            return null;
        }

        $target = (int) ceil($timed * 0.95);
        $seen   = 0;
        foreach (RollupTables::HISTOGRAM as $bound => $column) {
            $seen += $this->histogram[substr($column, 2)] ?? 0;
            if ($seen >= $target) {
                return $bound;
            }
        }

        return max(10001, $this->durationMsMax);
    }
}
