<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use InvalidArgumentException;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;

use function array_map;
use function count;
use function is_int;
use function sprintf;

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
        return (int) $this->repository->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->where(sprintf('e.%s = :value', $field))
            ->setParameter('value', $this->nonEmpty($field, $value))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Keyset batches: select up to `purge_batch_size` matching ids after the last one seen, delete them, repeat.
     *
     * @param 'clientIp'|'userIdentifier' $field Entity field holding the identifier
     */
    private function delete(string $field, string $value): int
    {
        $value   = $this->nonEmpty($field, $value);
        $size    = $this->retentionConfig['purge_batch_size'] ?? Configuration::DEFAULT_PURGE_BATCH_SIZE;
        $size    = is_int($size) && $size > 0 ? $size : Configuration::DEFAULT_PURGE_BATCH_SIZE;
        $total   = 0;
        $afterId = 0;

        do {
            /** @var list<int|string> $rows */
            $rows = $this->repository->createQueryBuilder('e')
                ->select('e.id')
                ->where(sprintf('e.%s = :value', $field))
                ->andWhere('e.id > :afterId')
                ->setParameter('value', $value)
                ->setParameter('afterId', $afterId)
                ->orderBy('e.id', 'ASC')
                ->setMaxResults($size)
                ->getQuery()
                ->getSingleColumnResult();
            $ids = array_map(intval(...), $rows);
            if ($ids === []) {
                break;
            }
            $total += $this->repository->deleteByIds($ids);
            $afterId = $ids[count($ids) - 1];
        } while (count($ids) === $size);

        return $total;
    }

    private function nonEmpty(string $field, string $value): string
    {
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The %s to erase must not be empty.', $field));
        }

        return $value;
    }
}
