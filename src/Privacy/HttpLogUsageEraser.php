<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use Generator;
use InvalidArgumentException;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;

use function count;
use function is_int;
use function sprintf;
use function str_contains;

/**
 * Default {@see HttpLogUsageEraserInterface}: per-call rows through the entity (DQL, configured table name), buckets
 * through {@see UsageRollupStore}. Without the usage columns mapped there are no usage rows; without the rollup no
 * buckets.
 */
final readonly class HttpLogUsageEraser implements HttpLogUsageEraserInterface
{
    /**
     * @param array<string, mixed> $retentionConfig `nowo_http_log.retention` (its `purge_batch_size` sizes each delete)
     */
    public function __construct(
        private HttpLogEntryRepository $repository,
        private ?UsageRollupStore $rollup = null,
        private array $retentionConfig = [],
    ) {
    }

    public function countByCredential(string $kind, string $id): int
    {
        $count = 0;
        foreach ($this->idBatches(['credentialKind' => $this->checked('credential kind', $kind), 'credentialId' => $this->checked('credential id', $id)]) as $ids) {
            $count += count($ids);
        }

        return $count;
    }

    public function eraseCredential(string $kind, string $id): int
    {
        $criteria = ['credentialKind' => $this->checked('credential kind', $kind), 'credentialId' => $this->checked('credential id', $id)];
        $deleted  = $this->delete($criteria);
        $this->rollup?->foldCredential($kind, $id);

        return $deleted;
    }

    public function eraseTenant(string $tenantKey): int
    {
        $deleted = $this->delete(['tenantKey' => $this->checked('tenant key', $tenantKey)]);

        return $deleted + ($this->rollup?->deleteTenant($tenantKey) ?? 0);
    }

    /** @param array<string, string> $criteria */
    private function delete(array $criteria): int
    {
        $total = 0;
        foreach ($this->idBatches($criteria) as $ids) {
            $total += $this->repository->deleteByIds($ids);
        }

        return $total;
    }

    /**
     * Ids of the rows whose stored values are byte-identical to `$criteria` (the database `=` only pre-filters, see
     * {@see HttpLogPersonalDataEraser}), in keyset batches.
     *
     * @param array<string, string> $criteria entity field => exact value
     *
     * @return Generator<int, non-empty-list<int>>
     */
    private function idBatches(array $criteria): Generator
    {
        if (!$this->repository->hasUsageColumns()) {
            return;
        }

        $size    = $this->retentionConfig['purge_batch_size'] ?? Configuration::DEFAULT_PURGE_BATCH_SIZE;
        $size    = is_int($size) && $size > 0 ? $size : Configuration::DEFAULT_PURGE_BATCH_SIZE;
        $afterId = 0;

        do {
            $qb = $this->repository->createQueryBuilder('e')
                ->select('e.id AS id')
                ->andWhere('e.id > :afterId')
                ->setParameter('afterId', $afterId)
                ->orderBy('e.id', 'ASC')
                ->setMaxResults($size);
            foreach ($criteria as $field => $value) {
                $qb->addSelect(sprintf('e.%1$s AS %1$s', $field))
                    ->andWhere(sprintf('e.%1$s = :%1$s', $field))
                    ->setParameter($field, $value);
            }
            /** @var list<array<string, int|string|null>> $rows */
            $rows = $qb->getQuery()->getArrayResult();
            if ($rows === []) {
                return;
            }

            $ids = [];
            foreach ($rows as $row) {
                $exact = true;
                foreach ($criteria as $field => $value) {
                    $exact = $exact && $row[$field] === $value;
                }
                if ($exact) {
                    $ids[] = (int) $row['id'];
                }
            }
            $afterId = (int) $rows[count($rows) - 1]['id'];
            if ($ids !== []) {
                yield $ids;
            }
        } while (count($rows) === $size);
    }

    private function checked(string $what, string $value): string
    {
        if ($value === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException(sprintf('The %s to erase must not be empty or contain a NUL byte.', $what));
        }

        return $value;
    }
}
