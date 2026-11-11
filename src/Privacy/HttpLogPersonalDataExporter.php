<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use DateTimeImmutable;
use Generator;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;

use function array_chunk;
use function array_slice;
use function count;
use function is_int;
use function is_numeric;
use function is_string;
use function krsort;
use function sprintf;
use function strcmp;
use function usort;

use const DATE_ATOM;

/**
 * Default {@see HttpLogPersonalDataExporterInterface}: one keyset scan of the subject's candidates (DQL through the
 * entity, so the configured table name applies), exact `===` re-check in PHP, then the newest `export_limit` entries
 * read by primary key. Memory stays bounded by the limit and the number of distinct IPs, whatever the log size.
 */
final readonly class HttpLogPersonalDataExporter implements HttpLogPersonalDataExporterInterface
{
    /** Primary keys per `IN (...)` read (below SQLite's oldest bound-parameter limit). */
    private const READ_CHUNK = 500;

    /**
     * @param int $exportLimit `nowo_http_log.personal_data.export_limit`: entries exported at most (newest first)
     * @param array<string, mixed> $retentionConfig `nowo_http_log.retention` (its `purge_batch_size` sizes each scan batch)
     */
    public function __construct(
        private HttpLogEntryRepository $repository,
        private int $exportLimit = Configuration::DEFAULT_PERSONAL_DATA_EXPORT_LIMIT,
        private array $retentionConfig = [],
    ) {
    }

    public function export(HttpLogPersonalDataSubject $subject): array
    {
        $limit   = $this->exportLimit > 0 ? $this->exportLimit : Configuration::DEFAULT_PERSONAL_DATA_EXPORT_LIMIT;
        $total   = 0;
        $newest  = [];
        $windows = [];
        foreach ($this->exactRows($subject) as $row) {
            ++$total;
            // Sort key: creation time, then id (both zero-padded, so a string sort is chronological).
            $newest[sprintf('%s|%020d', $row['created_at']->format('Y-m-d H:i:s.u'), $row['id'])] = $row['id'];
            if (count($newest) > 2 * $limit) {
                krsort($newest);
                $newest = array_slice($newest, 0, $limit, true);
            }
            $windows = $this->widen($windows, $row);
        }
        krsort($newest);
        $newest = array_slice($newest, 0, $limit, true);

        $windowRows = [];
        foreach ($this->sortedWindows($windows) as $window) {
            $windowRows[] = $window->toArray();
        }

        return [
            'subject'           => $subject->toArray(),
            'total'             => $total,
            'limit'             => $limit,
            'truncated'         => $total > count($newest),
            'client_ip_windows' => $windowRows,
            'entries'           => $this->entries($newest),
        ];
    }

    public function clientIpWindows(string $userIdentifier): array
    {
        $windows = [];
        foreach ($this->exactRows(HttpLogPersonalDataSubject::fromUserIdentifier($userIdentifier)) as $row) {
            $windows = $this->widen($windows, $row);
        }

        return $this->sortedWindows($windows);
    }

    /**
     * Rows whose stored value is byte-identical to the subject's (a case- or accent-insensitive collation only
     * pre-filters), in keyset batches of `retention.purge_batch_size`.
     *
     * @return Generator<int, array{id: int, client_ip: string|null, created_at: DateTimeImmutable}>
     */
    private function exactRows(HttpLogPersonalDataSubject $subject): Generator
    {
        $field   = $subject->isClientIp() ? 'clientIp' : 'userIdentifier';
        $size    = $this->retentionConfig['purge_batch_size'] ?? Configuration::DEFAULT_PURGE_BATCH_SIZE;
        $size    = is_int($size) && $size > 0 ? $size : Configuration::DEFAULT_PURGE_BATCH_SIZE;
        $afterId = 0;

        do {
            /** @var list<array{id: int|string, value: string|null, client_ip: string|null, created_at: DateTimeImmutable|string}> $rows */
            $rows = $this->repository->createQueryBuilder('e')
                ->select('e.id AS id', sprintf('e.%s AS value', $field), 'e.clientIp AS client_ip', 'e.createdAt AS created_at')
                ->where(sprintf('e.%s = :value', $field))
                ->andWhere('e.id > :afterId')
                ->setParameter('value', $subject->value)
                ->setParameter('afterId', $afterId)
                ->orderBy('e.id', 'ASC')
                ->setMaxResults($size)
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $row) {
                if ($row['value'] === $subject->value) {
                    yield ['id' => (int) $row['id'], 'client_ip' => $row['client_ip'], 'created_at' => self::date($row['created_at'])];
                }
            }
            $afterId = $rows === [] ? $afterId : (int) $rows[count($rows) - 1]['id'];
        } while (count($rows) === $size);
    }

    /**
     * @param array<string, array{0: DateTimeImmutable, 1: DateTimeImmutable}> $windows client IP => [first, last]
     * @param array{id: int, client_ip: string|null, created_at: DateTimeImmutable} $row
     *
     * @return array<string, array{0: DateTimeImmutable, 1: DateTimeImmutable}>
     */
    private function widen(array $windows, array $row): array
    {
        $ip = $row['client_ip'];
        if ($ip === null || $ip === '') {
            return $windows;
        }
        $at             = $row['created_at'];
        [$first, $last] = $windows[$ip] ?? [$at, $at];
        $windows[$ip]   = [$at < $first ? $at : $first, $at > $last ? $at : $last];

        return $windows;
    }

    /**
     * @param array<string, array{0: DateTimeImmutable, 1: DateTimeImmutable}> $windows
     *
     * @return list<HttpLogClientIpWindow>
     */
    private function sortedWindows(array $windows): array
    {
        $out = [];
        foreach ($windows as $ip => [$first, $last]) {
            $out[] = new HttpLogClientIpWindow((string) $ip, $first, $last);
        }
        usort($out, static fn (HttpLogClientIpWindow $a, HttpLogClientIpWindow $b): int => $a->firstSeenAt <=> $b->firstSeenAt ?: strcmp($a->ip, $b->ip));

        return $out;
    }

    /**
     * Export columns of the selected entries, in the order of `$newest` (newest first).
     *
     * @param array<string, int> $newest sort key => id
     *
     * @return list<array{created_at: string, method: string, scheme: string|null, host: string|null, path: string, route_name: string|null, status_code: int|null, duration_ms: float|null, client_ip: string|null, channel: string|null}>
     */
    private function entries(array $newest): array
    {
        $usage  = $this->repository->hasUsageColumns();
        $byId   = [];
        $select = ['e.id AS id', 'e.createdAt AS created_at', 'e.method AS method', 'e.scheme AS scheme', 'e.host AS host', 'e.path AS path', 'e.routeName AS route_name', 'e.statusCode AS status_code', 'e.durationMs AS duration_ms', 'e.clientIp AS client_ip'];
        if ($usage) {
            $select[] = 'e.channel AS channel';
        }
        foreach (array_chunk($newest, self::READ_CHUNK) as $ids) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $this->repository->createQueryBuilder('e')
                ->select(...$select)
                ->where('e.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $byId[(int) $row['id']] = $row;
            }
        }

        $out = [];
        foreach ($newest as $id) {
            $row = $byId[$id] ?? null; // null: deleted between the scan and the read
            if ($row !== null) {
                $out[] = [
                    'created_at'  => self::date($row['created_at'])->format(DATE_ATOM),
                    'method'      => (string) $row['method'],
                    'scheme'      => self::stringOrNull($row['scheme']),
                    'host'        => self::stringOrNull($row['host']),
                    'path'        => (string) $row['path'],
                    'route_name'  => self::stringOrNull($row['route_name']),
                    'status_code' => is_numeric($row['status_code']) ? (int) $row['status_code'] : null,
                    'duration_ms' => is_numeric($row['duration_ms']) ? (float) $row['duration_ms'] : null,
                    'client_ip'   => self::stringOrNull($row['client_ip']),
                    'channel'     => self::stringOrNull($row['channel'] ?? null),
                ];
            }
        }

        return $out;
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        return $value instanceof DateTimeImmutable ? $value : new DateTimeImmutable(is_string($value) ? $value : 'now');
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
