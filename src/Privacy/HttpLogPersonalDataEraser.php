<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use Generator;
use InvalidArgumentException;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;

use function count;
use function is_int;
use function sprintf;
use function str_contains;

/**
 * Default {@see HttpLogPersonalDataEraserInterface}: DQL through the entity, so the configured table name always applies.
 */
final readonly class HttpLogPersonalDataEraser implements HttpLogPersonalDataEraserInterface
{
    /**
     * @param HttpLogEntryRepository $repository Entry repository
     * @param array<string, mixed> $retentionConfig `nowo_http_log.retention` (its `purge_batch_size` sizes each delete)
     */
    public function __construct(
        private HttpLogEntryRepository $repository,
        private array $retentionConfig = [],
    ) {
    }

    public function countByUserIdentifier(string $userIdentifier): int
    {
        return $this->count('userIdentifier', $userIdentifier);
    }

    public function deleteByUserIdentifier(string $userIdentifier): int
    {
        return $this->delete('userIdentifier', $userIdentifier);
    }

    public function countByClientIp(string $clientIp): int
    {
        return $this->count('clientIp', $clientIp);
    }

    public function deleteByClientIp(string $clientIp): int
    {
        return $this->delete('clientIp', $clientIp);
    }

    /**
     * @param 'clientIp'|'userIdentifier' $field Entity field holding the identifier
     */
    private function count(string $field, string $value): int
    {
        $count = 0;
        foreach ($this->exactIdBatches($field, $value) as $ids) {
            $count += count($ids);
        }

        return $count;
    }

    /**
     * @param 'clientIp'|'userIdentifier' $field Entity field holding the identifier
     */
    private function delete(string $field, string $value): int
    {
        $total = 0;
        foreach ($this->exactIdBatches($field, $value) as $ids) {
            $total += $this->repository->deleteByIds($ids);
        }

        return $total;
    }

    /**
     * Ids of the entries whose stored value is byte-identical to `$value`, in keyset batches of `purge_batch_size`.
     *
     * The database `=` only pre-filters: a case- or accent-insensitive collation (MySQL utf8mb4 `*_ci`) also returns
     * `ANA` / `Ana` / `ána` for `ana`, or `ß` for `ss`, so every candidate is compared with `===` in PHP and only exact
     * matches are counted or deleted. The keyset cursor advances over every candidate, matching or not.
     *
     * @param 'clientIp'|'userIdentifier' $field Entity field holding the identifier
     *
     * @return Generator<int, non-empty-list<int>>
     */
    private function exactIdBatches(string $field, string $value): Generator
    {
        $value   = $this->checked($field, $value);
        $size    = $this->retentionConfig['purge_batch_size'] ?? Configuration::DEFAULT_PURGE_BATCH_SIZE;
        $size    = is_int($size) && $size > 0 ? $size : Configuration::DEFAULT_PURGE_BATCH_SIZE;
        $afterId = 0;

        do {
            /** @var list<array{id: int|string, value: string|null}> $rows */
            $rows = $this->repository->createQueryBuilder('e')
                ->select('e.id AS id', sprintf('e.%s AS value', $field))
                ->where(sprintf('e.%s = :value', $field))
                ->andWhere('e.id > :afterId')
                ->setParameter('value', $value)
                ->setParameter('afterId', $afterId)
                ->orderBy('e.id', 'ASC')
                ->setMaxResults($size)
                ->getQuery()
                ->getArrayResult();
            if ($rows === []) {
                return;
            }

            $ids = [];
            foreach ($rows as $row) {
                if ($row['value'] === $value) {
                    $ids[] = (int) $row['id'];
                }
            }
            $afterId = (int) $rows[count($rows) - 1]['id'];
            if ($ids !== []) {
                yield $ids;
            }
        } while (count($rows) === $size);
    }

    /**
     * Rejects values the database could not match exactly: empty, or with a NUL byte (PDO cuts bound values at NUL).
     */
    private function checked(string $field, string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The %s to erase must not be empty.', $field));
        }
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException(sprintf('The %s to erase must not contain a NUL byte.', $field));
        }

        return $value;
    }
}
