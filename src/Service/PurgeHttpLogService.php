<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Usage\HttpLogChannel;
use Psr\Clock\ClockInterface;

use function array_key_exists;
use function array_keys;
use function array_sum;
use function array_unique;
use function array_values;
use function implode;
use function in_array;
use function is_int;
use function sprintf;

/**
 * Purges HTTP log entries by retention policy or explicit criteria.
 *
 * With outbound or mailer capture enabled (HL-1 §10) the retention runs per scope: `web` rows (no channel) by
 * `retention.days`, `outbound` / `mailer` rows by their own `retention_days`. A days override without scopes keeps
 * its 1.14 meaning: every row of every channel.
 */
final readonly class PurgeHttpLogService
{
    /**
     * @param array<string, mixed> $retentionConfig
     * @param array<string, int|null> $channelRetention enabled usage channel => retention days (null = forever)
     */
    public function __construct(
        private HttpLogEntryRepository $repository,
        private ClockInterface $clock,
        private array $retentionConfig,
        private array $channelRetention = [],
    ) {
    }

    public function purgeByRetention(?int $daysOverride = null, bool $dryRun = false): int
    {
        if ($daysOverride !== null && $daysOverride < 0) {
            throw new InvalidArgumentException(sprintf('Retention days must be a non-negative integer, got %d.', $daysOverride));
        }

        if ($daysOverride === null && $this->channelRetention !== []) {
            return array_sum($this->purgeScopes([], null, $dryRun));
        }

        $days = $daysOverride ?? $this->retentionConfig['days'] ?? null;
        if ($days === null) {
            return 0;
        }

        return $this->purgeOlderThan($this->cutoff($days), $dryRun);
    }

    /**
     * Purge scopes of this configuration: `web`, then each enabled usage channel.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return [HttpLogChannel::WEB, ...array_keys($this->channelRetention)];
    }

    /**
     * Runs each scope with its own cutoff (`$days` overrides the retention of the scopes given). A scope whose
     * retention is null is skipped (reported as 0).
     *
     * @param list<string> $scopes empty = every scope of {@see scopes()}
     *
     * @throws InvalidArgumentException on an unknown scope or negative days
     *
     * @return array<string, int> scope => rows purged (or counted with `$dryRun`)
     */
    public function purgeScopes(array $scopes = [], ?int $days = null, bool $dryRun = false): array
    {
        if ($days !== null && $days < 0) {
            throw new InvalidArgumentException(sprintf('Retention days must be a non-negative integer, got %d.', $days));
        }

        $known  = $this->scopes();
        $scopes = $scopes === [] ? $known : array_values(array_unique($scopes));
        $result = [];
        foreach ($scopes as $scope) {
            if (!in_array($scope, $known, true)) {
                throw new InvalidArgumentException(sprintf('Unknown purge scope "%s"; expected one of: %s.', $scope, implode(', ', $known)));
            }

            $scopeDays = $days ?? $this->retentionDays($scope);
            if ($scopeDays === null) {
                $result[$scope] = 0;

                continue;
            }

            $before         = $this->cutoff($scopeDays);
            $result[$scope] = $dryRun
                ? $this->repository->countFiltered(['createdTo' => $before->modify('-1 second'), 'channel' => $scope])
                : $this->repository->deleteOlderThan($before, $this->batchSize(), $scope);
        }

        return $result;
    }

    /** Cutoff of a retention in days: entries created before it are purged. */
    public function cutoff(int $days): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now())
            ->modify(sprintf('-%d days', $days));
    }

    public function purgeOlderThan(DateTimeImmutable $before, bool $dryRun = false): int
    {
        if ($dryRun) {
            return $this->repository->countFiltered([
                'createdTo' => $before->modify('-1 second'),
            ]);
        }

        return $this->repository->deleteOlderThan($before, $this->batchSize());
    }

    /** @param array<string, mixed> $criteria */
    public function purgeByCriteria(array $criteria, bool $dryRun = false): int
    {
        if ($dryRun) {
            return $this->repository->countFiltered($criteria);
        }

        $total = 0;

        do {
            $ids = $this->repository->findIdsFiltered($criteria, $this->batchSize());
            $total += $this->repository->deleteByIds($ids);
        } while ($ids !== []);

        return $total;
    }

    private function retentionDays(string $scope): ?int
    {
        $days = array_key_exists($scope, $this->channelRetention)
            ? $this->channelRetention[$scope]
            : ($this->retentionConfig['days'] ?? null);

        return is_int($days) ? $days : null;
    }

    private function batchSize(): int
    {
        $size = $this->retentionConfig['purge_batch_size'] ?? Configuration::DEFAULT_PURGE_BATCH_SIZE;

        return is_int($size) && $size > 0 ? $size : Configuration::DEFAULT_PURGE_BATCH_SIZE;
    }
}
