<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Psr\Clock\ClockInterface;

use function sprintf;

/**
 * Purges HTTP log entries by retention policy or explicit criteria.
 */
final class PurgeHttpLogService
{
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

        $page  = 1;
        $total = 0;

        do {
            $result = $this->repository->findFiltered($criteria, $page, 500);
            $ids    = array_map(static fn (HttpLogEntry $entry): int => (int) $entry->getId(), $result['items']);
            $total += $this->repository->deleteByIds($ids);
        } while ($result['items'] !== []);

        return $total;
    }
}
