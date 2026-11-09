<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Command;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Rollup\RollupBucket;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Nowo\HttpLogBundle\Usage\HttpLogChannel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function date_default_timezone_get;
use function implode;
use function in_array;
use function is_numeric;
use function is_string;
use function sprintf;

/**
 * Builds rollup buckets from per-call rows (HL-1 §9.5), for adoption on a host that already logged rows: only for
 * the days (UTC) on which a channel has no bucket yet, never rewriting a day that has some, never today (live
 * events count it) and never before the rollup cutoff. Counts of a sampled channel are lower bounds (its unsampled
 * successes have no row). Stored times are read in the PHP default time zone (the one they were written in).
 */
#[AsCommand(
    name: 'nowo:http-log:rollup:backfill',
    description: 'Build daily usage rollup buckets from per-call rows, for days that have none',
)]
final class RollupBackfillCommand extends Command
{
    private const BATCH = 1000;

    /** Columns read from a row (entity fields). */
    private const FIELDS = ['id', 'createdAt', 'statusCode', 'durationMs'];

    private const USAGE_FIELDS = ['provider', 'credentialKind', 'credentialId', 'tenantKey', 'errorKind', 'retryCount', 'responseBytes'];

    /**
     * @param array<string, bool> $channels rollup channel => true when its successes are sampled (counts are lower bounds)
     * @param list<string> $excludedProviders outbound providers with `rollup: false`
     */
    public function __construct(
        private readonly UsageRollupStore $store,
        private readonly ManagerRegistry $registry,
        private readonly array $channels = [],
        private readonly array $excludedProviders = [],
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'First UTC day (Y-m-d); default: the rollup cutoff')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Last UTC day (Y-m-d); default and latest: yesterday')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the buckets without writing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $dryRun    = (bool) $input->getOption('dry-run');
        $utc       = new DateTimeZone('UTC');
        $yesterday = (new DateTimeImmutable($this->store->today(), $utc))->modify('-1 day')->format('Y-m-d');
        $cutoff    = $this->store->cutoffDay();
        $from      = $this->day($this->str($input->getOption('from'))) ?? $cutoff ?? $yesterday;
        $to        = $this->day($this->str($input->getOption('to'))) ?? $yesterday;
        if ($from === false || $to === false) {
            $io->error('--from and --to must be days in the Y-m-d format.');

            return Command::INVALID;
        }
        if ($to > $yesterday) {
            $io->note(sprintf('Today is counted by live events: backfilling up to %s.', $yesterday));
            $to = $yesterday;
        }
        if ($cutoff !== null && $from < $cutoff) {
            $io->note(sprintf('Days before the rollup cutoff %s are not kept: starting there.', $cutoff));
            $from = $cutoff;
        }

        $written = 0;
        for ($day = new DateTimeImmutable($from, $utc); $day->format('Y-m-d') <= $to; $day = $day->modify('+1 day')) {
            foreach ($this->channels as $channel => $sampled) {
                $written += $this->backfill($io, $day, $channel, $sampled, $dryRun);
            }
        }

        $io->success(sprintf($dryRun ? 'Would write %d rollup buckets.' : 'Wrote %d rollup buckets.', $written));

        return Command::SUCCESS;
    }

    private function backfill(SymfonyStyle $io, DateTimeImmutable $day, string $channel, bool $sampled, bool $dryRun): int
    {
        $label = $day->format('Y-m-d') . ' ' . $channel;
        if ($this->store->hasBuckets($day->format('Y-m-d'), $channel)) {
            $io->writeln($label . ': has buckets, skipped');

            return 0;
        }

        [$rows, $buckets] = $this->aggregate($day, $channel);
        if ($rows === 0) {
            return 0;
        }
        if (!$dryRun) {
            $connection = $this->store->connection();
            // @igor-ignore - closure run once inside the transaction, not kept by the connection
            $connection->transactional(function (Connection $connection) use ($buckets): void {
                foreach ($buckets as $bucket) {
                    $this->store->upsert($connection, $bucket);
                }
            });
        }
        $io->writeln(sprintf('%s: %d rows, %d buckets%s', $label, $rows, count($buckets), $sampled ? ' (lower bound: sampled channel)' : ''));

        return count($buckets);
    }

    /**
     * Buckets of one UTC day and channel, summed over its rows (read in keyset batches).
     *
     * @return array{0: int, 1: array<string, array<string, int|string>>} rows read, buckets by key
     */
    private function aggregate(DateTimeImmutable $day, string $channel): array
    {
        $manager = $this->registry->getManagerForClass(HttpLogEntry::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine EntityManager handles ' . HttpLogEntry::class . '.');
        }
        $metadata = $manager->getClassMetadata(HttpLogEntry::class);
        $usage    = $metadata->hasField('channel');
        if ($channel !== HttpLogChannel::WEB && !$usage) {
            return [0, []];
        }

        $local   = new DateTimeZone(date_default_timezone_get());
        $start   = $day->setTimezone($local)->format('Y-m-d H:i:s');
        $end     = $day->modify('+1 day')->setTimezone($local)->format('Y-m-d H:i:s');
        $fields  = $usage ? [...self::FIELDS, ...self::USAGE_FIELDS] : self::FIELDS;
        $buckets = [];
        $rows    = 0;
        $afterId = 0;
        do {
            $batch = $this->batch($manager->getConnection(), $metadata, $fields, $channel, $usage, $start, $end, $afterId);
            foreach ($batch as $row) {
                $afterId = (int) $row['id'];
                if ($channel === HttpLogChannel::OUTBOUND && in_array($row['provider'] ?? null, $this->excludedProviders, true)) {
                    continue;
                }
                ++$rows;
                $event = RollupBucket::fromValues(
                    new DateTimeImmutable((string) $row['createdAt'], $local),
                    $channel,
                    $this->str($row['provider'] ?? null),
                    $this->str($row['credentialKind'] ?? null),
                    $this->str($row['credentialId'] ?? null),
                    $this->str($row['tenantKey'] ?? null),
                    $this->int($row['statusCode']),
                    $this->str($row['errorKind'] ?? null),
                    $this->int($row['retryCount'] ?? null),
                    $this->int($row['responseBytes'] ?? null),
                    is_numeric($row['durationMs']) ? (float) $row['durationMs'] : null,
                    true,
                    $this->store->errorStatusMin($channel),
                );
                $key           = implode("\0", [$event['provider'], $event['credential_kind'], $event['credential_id'], $event['tenant_key']]);
                $buckets[$key] = isset($buckets[$key]) ? RollupBucket::add($buckets[$key], $event) : $event;
            }
        } while (count($batch) === self::BATCH);

        return [$rows, $buckets];
    }

    /**
     * @param ClassMetadata<HttpLogEntry> $metadata
     * @param list<string> $fields
     *
     * @return list<array<string, mixed>> rows keyed by entity field
     */
    private function batch(Connection $connection, ClassMetadata $metadata, array $fields, string $channel, bool $usage, string $start, string $end, int $afterId): array
    {
        $qb = $connection->createQueryBuilder()
            ->from($metadata->getTableName())
            ->where($metadata->getColumnName('createdAt') . ' >= :start')
            ->andWhere($metadata->getColumnName('createdAt') . ' < :end')
            ->andWhere($metadata->getColumnName('id') . ' > :afterId')
            ->orderBy($metadata->getColumnName('id'))
            ->setMaxResults(self::BATCH)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('afterId', $afterId);
        foreach ($fields as $field) {
            $qb->addSelect($metadata->getColumnName($field));
        }
        if ($channel === HttpLogChannel::WEB) {
            if ($usage) {
                $qb->andWhere($metadata->getColumnName('channel') . ' IS NULL');
            }
        } else {
            $qb->andWhere($metadata->getColumnName('channel') . ' = :channel')->setParameter('channel', $channel);
        }

        $rows = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $values = [];
            foreach ($fields as $field) {
                $values[$field] = $row[$metadata->getColumnName($field)] ?? null;
            }
            $rows[] = $values;
        }

        return $rows;
    }

    /** @return false|string|null null when not given, false when invalid */
    private function day(?string $value): string|false|null
    {
        if ($value === null) {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $day !== false && $day->format('Y-m-d') === $value ? $value : false;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
