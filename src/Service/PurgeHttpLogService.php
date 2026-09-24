<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Psr\Clock\ClockInterface;

use function sprintf;

/**
 * Purges HTTP log entries by retention policy or explicit criteria.
 */
final class PurgeHttpLogService
{
    private const BATCH_SIZE = 500;

    /**
     * @param array<string, mixed> $retentionConfig
     */
    public function __construct(
        private readonly HttpLogEntryRepository $repository,
        private readonly ClockInterface $clock,
        private readonly array $retentionConfig,
    ) {
    }

    public function purgeByRetention(?int $daysOverride = null, bool $dryRun = false): int
    {
        $days = $daysOverride ?? $this->retentionConfig['days'] ?? null;
        if ($days === null) {
            return 0;
        }

        $before = DateTimeImmutable::createFromInterface($this->clock->now())
            ->modify(sprintf('-%d days', $days));

        return $this->purgeOlderThan($before, $dryRun);
    }

    public function purgeOlderThan(DateTimeImmutable $before, bool $dryRun = false): int
    {
        if ($dryRun) {
            return $this->repository->countFiltered([
                'createdTo' => $before->modify('-1 second'),
            ]);
        }

        return $this->repository->deleteOlderThan($before);
    }

    /** @param array<string, mixed> $criteria */
    public function purgeByCriteria(array $criteria, bool $dryRun = false): int
    {
        if ($dryRun) {
            return $this->repository->countFiltered($criteria);
        }

        $total = 0;

        do {
            $ids = $this->repository->findIdsFiltered($criteria, self::BATCH_SIZE);
            $total += $this->repository->deleteByIds($ids);
        } while ($ids !== []);

        return $total;
    }
}
