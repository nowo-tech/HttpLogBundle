<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Rollup;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Nowo\HttpLogBundle\Usage\UsageCapture;

use function array_fill_keys;
use function intdiv;
use function max;
use function min;
use function round;

/**
 * Column values of one bucket (HL-1 §9.1, §9.2): built for one event, or summed over rows by the backfill. Days and
 * times are UTC whatever `date.timezone` says.
 */
final class RollupBucket
{
    /** Largest value of the `duration_ms_max` INT column. */
    private const INT_MAX = 2147483647;

    /** UTC day (`Y-m-d`) of a capture time. */
    public static function day(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    }

    /** UTC `Y-m-d H:i:s` of a capture time. */
    public static function time(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The bucket of one event: `calls` 1 and the matching counters.
     *
     * @param int $errorStatusMin status from which the call counts as an error (a named channel's `error_status_min`)
     *
     * @return array<string, int|string>
     */
    public static function fromCapture(UsageCapture $capture, int $errorStatusMin = 400): array
    {
        return self::fromValues(
            $capture->createdAt,
            $capture->channel,
            $capture->provider,
            $capture->credentialKind,
            $capture->credentialId,
            $capture->tenantKey,
            $capture->statusCode,
            $capture->errorKind,
            $capture->retryCount,
            $capture->responseBytes,
            $capture->durationMs,
            $capture->row,
            $errorStatusMin,
        );
    }

    /**
     * @return array<string, int|string>
     */
    public static function fromValues(
        DateTimeInterface $createdAt,
        string $channel,
        ?string $provider,
        ?string $credentialKind,
        ?string $credentialId,
        ?string $tenantKey,
        ?int $status,
        ?string $errorKind,
        ?int $retries,
        ?int $bytes,
        ?float $durationMs,
        bool $row,
        int $errorStatusMin = 400,
    ): array {
        $counters                     = array_fill_keys(RollupTables::COUNTER_COLUMNS, 0);
        $counters['calls']            = 1;
        $counters['errors']           = $errorKind !== null || ($status !== null && $status >= $errorStatusMin) ? 1 : 0;
        $counters['transport_errors'] = $errorKind !== null && $status === null ? 1 : 0;
        if ($status !== null && $status >= 200 && $status < 600) {
            $counters['status_' . intdiv($status, 100) . 'xx'] = 1;
        }
        $counters['retries']     = max(0, $retries ?? 0);
        $counters['bytes']       = max(0, $bytes ?? 0);
        $counters['rows_stored'] = $row ? 1 : 0;

        $max = 0;
        if ($durationMs !== null && $durationMs >= 0.0) {
            $ms                                           = (int) min((float) self::INT_MAX, round($durationMs));
            $max                                          = $ms;
            $counters['duration_ms_sum']                  = $ms;
            $counters[self::histogramColumn($durationMs)] = 1;
        }
        $time = self::time($createdAt);

        return [
            'day'             => self::day($createdAt),
            'channel'         => $channel,
            'provider'        => $provider ?? '',
            'credential_kind' => $credentialKind ?? '',
            'credential_id'   => $credentialId ?? '',
            'tenant_key'      => $tenantKey ?? '',
            ...$counters,
            'duration_ms_max' => $max,
            'first_seen_at'   => $time,
            'last_seen_at'    => $time,
        ];
    }

    /**
     * Adds `$event` to `$bucket` (same key): counters summed, max of the maxima, earliest first and latest last seen.
     *
     * @param array<string, int|string> $bucket
     * @param array<string, int|string> $event
     *
     * @return array<string, int|string>
     */
    public static function add(array $bucket, array $event): array
    {
        foreach (RollupTables::COUNTER_COLUMNS as $column) {
            $bucket[$column] = (int) $bucket[$column] + (int) $event[$column];
        }
        $bucket['duration_ms_max'] = max((int) $bucket['duration_ms_max'], (int) $event['duration_ms_max']);
        $bucket['first_seen_at']   = min((string) $bucket['first_seen_at'], (string) $event['first_seen_at']);
        $bucket['last_seen_at']    = max((string) $bucket['last_seen_at'], (string) $event['last_seen_at']);

        return $bucket;
    }

    /** Histogram column of a duration: the first bucket whose upper bound holds it. */
    public static function histogramColumn(float $durationMs): string
    {
        foreach (RollupTables::HISTOGRAM as $bound => $column) {
            if ($durationMs <= $bound) {
                return $column;
            }
        }

        return RollupTables::OVERFLOW_COLUMN;
    }
}
