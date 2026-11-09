<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Rollup;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use LogicException;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Psr\Clock\ClockInterface;
use Throwable;

use function array_column;
use function array_keys;
use function array_map;
use function count;
use function is_int;
use function min;
use function sprintf;
use function strval;
use function substr;

/**
 * The rollup tables on the connection of the entry's EntityManager (HL-1 §9): exactly-once writes, purge, erasure
 * folds and the backfill's inserts. Stateless; the connection is resolved per call.
 */
final readonly class UsageRollupStore
{
    /** Credential id an erased credential's buckets are folded into (outside the stored-token charset). */
    public const ERASED_CREDENTIAL_ID = '~erased';

    /**
     * @param int|null $retentionMonths calendar months kept, the current one included (null = forever)
     * @param array<string, int> $errorStatusMin channel => status from which a call counts as an error (default 400)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private RollupTables $tables,
        private ClockInterface $clock,
        private ?int $retentionMonths = 13,
        private int $dedupeDays = 7,
        private array $errorStatusMin = [],
    ) {
    }

    public function tables(): RollupTables
    {
        return $this->tables;
    }

    public function connection(): Connection
    {
        $manager = $this->registry->getManagerForClass(HttpLogEntry::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine EntityManager handles ' . HttpLogEntry::class . '.');
        }

        return $manager->getConnection();
    }

    /** Today, UTC (`Y-m-d`). */
    public function today(): string
    {
        return RollupBucket::day($this->clock->now());
    }

    /**
     * Oldest day the rollup keeps (HL-1 §9.5): the first day of (current UTC month − retention_months + 1), so 13 keeps
     * the same month last year. null = forever.
     */
    public function cutoffDay(?int $retentionMonths = null): ?string
    {
        $months = $retentionMonths ?? $this->retentionMonths;
        if ($months === null) {
            return null;
        }

        $month = DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month');

        return $month->modify(sprintf('-%d months', $months - 1))->format('Y-m-d');
    }

    public function errorStatusMin(string $channel): int
    {
        return $this->errorStatusMin[$channel] ?? 400;
    }

    /**
     * Counts `$capture` once (HL-1 §9.3). In one transaction: the per-call row (`$insertRow`, unique `request_id`) or
     * the ledger row (unique `event_id`), then the bucket upsert. A unique violation means the event was counted
     * before (Messenger redelivery, a crash between commit and ack): everything is rolled back and false returned.
     * An event older than the rollup cutoff is not counted; its row (if any) is still written.
     *
     * @param (Closure(Connection): void)|null $insertRow inserts the per-call row; null for a count-only event
     *
     * @return bool false when nothing was written (already counted, or a count-only event older than the cutoff)
     */
    public function record(UsageCapture $capture, ?Closure $insertRow): bool
    {
        $connection = $this->connection();
        $day        = RollupBucket::day($capture->createdAt);
        $cutoff     = $this->cutoffDay();

        try {
            if ($cutoff !== null && $day < $cutoff) {
                if (!$insertRow instanceof Closure) {
                    return false;
                }
                $insertRow($connection);

                return true;
            }

            $connection->beginTransaction();

            try {
                if ($insertRow instanceof Closure) {
                    $insertRow($connection);
                } else {
                    // the ledger day is the first processing day (JA-L2): dedupe_days then runs from the first count,
                    // not from the capture, so a late (backlogged) event keeps its full exactly-once window
                    $connection->insert($this->tables->eventSeen, ['event_id' => $capture->eventId, 'day' => $this->today()], [ParameterType::STRING, ParameterType::STRING]);
                }
                $this->upsert($connection, RollupBucket::fromCapture($capture, $this->errorStatusMin($capture->channel)));
                $connection->commit();
            } catch (Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }

                throw $exception;
            }
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Adds one bucket (or a sum of events) to the daily table.
     *
     * @param array<string, int|string> $bucket {@see RollupBucket} column => value
     */
    public function upsert(Connection $connection, array $bucket): void
    {
        ['sql' => $sql, 'repeat' => $repeat] = RollupSql::upsert($connection->getDatabasePlatform(), $this->tables->daily, array_map(strval(...), array_keys($bucket)));
        $connection->executeStatement($sql, RollupSql::parameters($bucket, $repeat));
    }

    /** True when the day already has a bucket of the channel (the backfill never rewrites such a day). */
    public function hasBuckets(string $day, string $channel): bool
    {
        $connection = $this->connection();

        return $connection->createQueryBuilder()
            ->select('1')
            ->from($this->tables->daily)
            ->where('day = :day')
            ->andWhere('channel = :channel')
            ->setParameter('day', $day)
            ->setParameter('channel', $channel)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne() !== false;
    }

    /**
     * Deletes buckets older than the cutoff (default: retention_months; `$days`: day < today − days, but never a day
     * of the current month), in batches of `$batchSize` ids.
     *
     * @return int buckets deleted (or counted with `$dryRun`); 0 when the retention is null
     */
    public function purgeBuckets(?int $days = null, int $batchSize = Configuration::DEFAULT_PURGE_BATCH_SIZE, bool $dryRun = false): int
    {
        // an override never deletes the current month (JA-I2): the cutoff is at most its first day
        $cutoff = $days === null ? $this->cutoffDay() : min($this->daysAgo($days), (string) $this->cutoffDay(1));
        if ($cutoff === null) {
            return 0;
        }

        return $this->purgeBefore($this->tables->daily, 'id', $cutoff, $batchSize, $dryRun);
    }

    /**
     * Deletes ledger rows first processed more than `dedupe_days` (or `$days`) ago.
     *
     * @return int ledger rows deleted (or counted with `$dryRun`)
     */
    public function purgeLedger(?int $days = null, int $batchSize = Configuration::DEFAULT_PURGE_BATCH_SIZE, bool $dryRun = false): int
    {
        return $this->purgeBefore($this->tables->eventSeen, 'event_id', $this->daysAgo($days ?? $this->dedupeDays), $batchSize, $dryRun);
    }

    /**
     * Folds every bucket of a credential into the bucket of the same day / channel / provider / tenant with credential
     * id {@see ERASED_CREDENTIAL_ID} (totals are kept), then deletes them, in one transaction.
     *
     * @return int buckets folded
     */
    public function foldCredential(string $kind, string $id): int
    {
        $connection = $this->connection();
        $platform   = $connection->getDatabasePlatform();
        $buckets    = $connection->createQueryBuilder()
            ->select('*')
            ->from($this->tables->daily)
            ->where(RollupSql::exact($platform, 'credential_kind', ':kind'))
            ->andWhere(RollupSql::exact($platform, 'credential_id', ':id'))
            ->setParameter('kind', $kind)
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAllAssociative();
        if ($buckets === []) {
            return 0;
        }

        // @igor-ignore - closure run once inside the transaction, not kept by the connection
        $connection->transactional(function (Connection $connection) use ($buckets): void {
            foreach ($buckets as $row) {
                /** @var array<string, int|string> $bucket */
                $bucket = [];
                foreach (RollupTables::KEY_COLUMNS as $column) {
                    $bucket[$column] = (string) $this->scalar($row[$column] ?? null);
                }
                foreach ([...RollupTables::COUNTER_COLUMNS, 'duration_ms_max'] as $column) {
                    $bucket[$column] = (int) $this->scalar($row[$column] ?? null);
                }
                foreach (['first_seen_at', 'last_seen_at'] as $column) {
                    $bucket[$column] = substr((string) $this->scalar($row[$column] ?? null), 0, 19);
                }
                $bucket['day']           = substr((string) $bucket['day'], 0, 10);
                $bucket['credential_id'] = self::ERASED_CREDENTIAL_ID;
                $this->upsert($connection, $bucket);
            }
            $this->deleteIds($connection, $this->tables->daily, 'id', array_map(intval(...), array_column($buckets, 'id')));
        });

        return count($buckets);
    }

    /**
     * Deletes every bucket of a tenant.
     *
     * @return int buckets deleted
     */
    public function deleteTenant(string $tenantKey): int
    {
        $connection = $this->connection();

        return (int) $connection->executeStatement(
            sprintf('DELETE FROM %s WHERE %s', $this->tables->daily, RollupSql::exact($connection->getDatabasePlatform(), 'tenant_key', ':tenant')),
            ['tenant' => $tenantKey],
        );
    }

    /** `Y-m-d` of today − `$days` (UTC). */
    private function daysAgo(int $days): string
    {
        if ($days < 0) {
            throw new InvalidArgumentException(sprintf('Retention days must be a non-negative integer, got %d.', $days));
        }

        return DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new DateTimeZone('UTC'))->modify(sprintf('-%d days', $days))->format('Y-m-d');
    }

    private function purgeBefore(string $table, string $key, string $day, int $batchSize, bool $dryRun): int
    {
        $connection = $this->connection();
        if ($dryRun) {
            return (int) $connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from($table)
                ->where('day < :day')
                ->setParameter('day', $day)
                ->executeQuery()
                ->fetchOne();
        }

        $total = 0;
        do {
            $ids = $connection->createQueryBuilder()
                ->select($key)
                ->from($table)
                ->where('day < :day')
                ->setParameter('day', $day)
                ->orderBy($key)
                ->setMaxResults($batchSize)
                ->executeQuery()
                ->fetchFirstColumn();
            $total += $this->deleteIds($connection, $table, $key, $ids);
        } while (count($ids) === $batchSize);

        return $total;
    }

    /** @param list<mixed> $ids */
    private function deleteIds(Connection $connection, string $table, string $key, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $connection->executeStatement(
            sprintf('DELETE FROM %s WHERE %s IN (:ids)', $table, $key),
            ['ids' => $ids],
            ['ids' => $key === 'id' ? ArrayParameterType::INTEGER : ArrayParameterType::STRING],
        );
    }

    private function scalar(mixed $value): int|string
    {
        return is_int($value) ? $value : (string) $value;
    }
}
