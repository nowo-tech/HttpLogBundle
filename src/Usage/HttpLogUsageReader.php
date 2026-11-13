<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Generator;
use InvalidArgumentException;
use LogicException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Rollup\RollupSql;
use Nowo\HttpLogBundle\Rollup\RollupTables;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;

use function array_map;
use function array_pop;
use function count;
use function in_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function sprintf;
use function substr;

/**
 * Default {@see HttpLogUsageReaderInterface}: DBAL queries on the rollup table and on the entry table (column names
 * from the class metadata, so the configured prefixes apply).
 */
final readonly class HttpLogUsageReader implements HttpLogUsageReaderInterface
{
    public const MAX_LIMIT = 200;

    /** Per-call fields returned by {@see calls()} (the HL-1 §4.1 allowlist). */
    private const CALL_FIELDS = [
        'id', 'requestId', 'channel', 'provider', 'method', 'scheme', 'host', 'path', 'routeName', 'statusCode',
        'errorKind', 'durationMs', 'responseBytes', 'retryCount', 'contentType', 'clientIp', 'userIdentifier',
        'credentialKind', 'credentialId', 'tenantKey', 'projectKey', 'messengerMessageId', 'externalMessageId',
        'createdAt',
    ];

    public function __construct(
        private ManagerRegistry $registry,
        private ?UsageRollupStore $rollup = null,
    ) {
    }

    public function daily(UsageQuery $query): array
    {
        $rows = [];
        foreach ($this->iterateDaily($query) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function iterateDaily(UsageQuery $query): Generator
    {
        $store      = $this->store();
        $connection = $store->connection();
        $qb         = $this->dailyQuery($connection, $store->tables(), $query);
        foreach ($qb->executeQuery()->iterateAssociative() as $row) {
            yield $this->dailyUsage($row, $query->groupBy);
        }
    }

    public function calls(CallQuery $query, ?string $cursor = null, int $limit = 50): CallPage
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('The page size must be 1..%d, got %d.', self::MAX_LIMIT, $limit));
        }
        if ($cursor !== null && preg_match('/^[1-9][0-9]{0,17}\z/', $cursor) !== 1) {
            throw new InvalidArgumentException('Invalid usage call cursor.');
        }
        if (in_array(HttpLogChannel::WEB, $query->channels, true)) {
            throw new InvalidArgumentException('calls() reads the usage channels only, not web rows.');
        }

        $manager = $this->registry->getManagerForClass(HttpLogEntry::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine EntityManager handles ' . HttpLogEntry::class . '.');
        }
        $metadata = $manager->getClassMetadata(HttpLogEntry::class);
        if (!$metadata->hasField('channel')) {
            throw new LogicException('No HttpLog usage channel is enabled: the usage columns are not mapped.');
        }

        $connection = $manager->getConnection();
        $platform   = $connection->getDatabasePlatform();
        $column     = $metadata->getColumnName(...);
        $qb         = $connection->createQueryBuilder()
            ->select(...array_map($column, self::CALL_FIELDS))
            ->from($metadata->getTableName())
            ->where($column('channel') . ' IS NOT NULL')
            ->orderBy($column('id'), 'DESC')
            ->setMaxResults($limit + 1);
        $this->scope($qb, $platform, $query->scope, $column('tenantKey'));
        $this->in($qb, $column('channel'), $query->channels, 'channels');
        $this->in($qb, $column('provider'), $query->providers, 'providers', $platform);
        $this->credential($qb, $platform, $column('credentialKind'), $column('credentialId'), $query->credentialKind, $query->credentialIds);
        if ($query->from instanceof DateTimeImmutable) {
            $qb->andWhere($column('createdAt') . ' >= :from')->setParameter('from', $query->from, Types::DATETIME_IMMUTABLE);
        }
        if ($query->to instanceof DateTimeImmutable) {
            $qb->andWhere($column('createdAt') . ' <= :to')->setParameter('to', $query->to, Types::DATETIME_IMMUTABLE);
        }
        if ($query->statusClass !== null) {
            $qb->andWhere(sprintf('%1$s >= %2$d AND %1$s < %3$d', $column('statusCode'), $query->statusClass * 100, ($query->statusClass + 1) * 100));
        }
        if ($query->errorKind !== null) {
            $qb->andWhere($column('errorKind') . ' = :errorKind')->setParameter('errorKind', $query->errorKind);
        }
        if ($cursor !== null) {
            $qb->andWhere($column('id') . ' < :cursor')->setParameter('cursor', (int) $cursor);
        }

        $items = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $values = [];
            foreach (self::CALL_FIELDS as $field) {
                $values[$field] = $row[$column($field)] ?? null;
            }
            $items[] = $this->usageCall($values);
        }
        $next = null;
        if (count($items) > $limit) {
            array_pop($items);
            $next = (string) $items[count($items) - 1]->id;
        }

        return new CallPage($items, $next);
    }

    public function lastUsed(UsageQuery $scope, string $kind, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $store      = $this->store();
        $connection = $store->connection();
        $platform   = $connection->getDatabasePlatform();
        $since      = (new DateTimeImmutable($store->today(), new DateTimeZone('UTC')))->modify('-29 days')->format('Y-m-d');
        $qb         = $connection->createQueryBuilder()
            ->select(
                'credential_id',
                'MAX(last_seen_at) AS last_seen_at',
                'SUM(CASE WHEN day >= :since THEN calls ELSE 0 END) AS calls30d',
                'SUM(CASE WHEN day >= :since THEN errors ELSE 0 END) AS errors30d',
            )
            ->from($store->tables()->daily)
            ->groupBy('credential_id')
            ->orderBy('credential_id')
            ->setParameter('since', $since);
        $this->scope($qb, $platform, $scope->scope, 'tenant_key');
        $this->in($qb, 'channel', $scope->channels, 'channels');
        $this->in($qb, 'provider', $scope->providers, 'providers', $platform);
        $this->credential($qb, $platform, 'credential_kind', 'credential_id', $kind, $ids);

        $result = [];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            // exact ids: compared byte for byte in SQL (binary NO PAD key columns, CAST AS BINARY on MySQL / MariaDB)
            $id          = (string) $row['credential_id'];
            $result[$id] = new CredentialUsage($id, $this->time($row['last_seen_at']) ?? new DateTimeImmutable('@0'), $this->int($row['calls30d']), $this->int($row['errors30d']));
        }

        return $result;
    }

    private function store(): UsageRollupStore
    {
        if (!$this->rollup instanceof UsageRollupStore) {
            throw new LogicException('The HttpLog rollup is not enabled (nowo_http_log.rollup.enabled).');
        }

        return $this->rollup;
    }

    private function dailyQuery(Connection $connection, RollupTables $tables, UsageQuery $query): QueryBuilder
    {
        $select = $query->groupBy;
        foreach ([...RollupTables::COUNTER_COLUMNS] as $counter) {
            $select[] = sprintf('SUM(%1$s) AS %1$s', $counter);
        }
        $select[] = 'MAX(duration_ms_max) AS duration_ms_max';
        $select[] = 'MIN(first_seen_at) AS first_seen_at';
        $select[] = 'MAX(last_seen_at) AS last_seen_at';

        $platform = $connection->getDatabasePlatform();
        $qb       = $connection->createQueryBuilder()->select(...$select)->from($tables->daily);
        $this->scope($qb, $platform, $query->scope, 'tenant_key');
        $this->in($qb, 'channel', $query->channels, 'channels');
        $this->in($qb, 'provider', $query->providers, 'providers', $platform);
        $this->credential($qb, $platform, 'credential_kind', 'credential_id', $query->credentialKind, $query->credentialIds);
        if ($query->fromDay !== null) {
            $qb->andWhere('day >= :fromDay')->setParameter('fromDay', $query->fromDay);
        }
        if ($query->toDay !== null) {
            $qb->andWhere('day <= :toDay')->setParameter('toDay', $query->toDay);
        }
        if ($query->groupBy !== []) {
            $qb->groupBy(...$query->groupBy);
            foreach ($query->groupBy as $key) {
                $qb->addOrderBy($key);
            }
        }

        return $qb;
    }

    private function scope(QueryBuilder $qb, AbstractPlatform $platform, UsageScope $scope, string $column): void
    {
        if ($scope->tenantKey !== null) {
            $qb->andWhere(RollupSql::exact($platform, $column, ':tenantKey'))->setParameter('tenantKey', $scope->tenantKey);
        }
    }

    /**
     * `column IN (…)`; byte-exact on MySQL / MariaDB when `$platform` is given (provider keys and credential ids are
     * identifiers, since 1.20.1; the channels are bundle names).
     *
     * @param list<string> $values
     */
    private function in(QueryBuilder $qb, string $column, array $values, string $name, ?AbstractPlatform $platform = null): void
    {
        if ($values === []) {
            return;
        }
        if (!$platform instanceof AbstractPlatform) {
            $qb->andWhere(sprintf('%s IN (:%s)', $column, $name))->setParameter($name, $values, ArrayParameterType::STRING);

            return;
        }

        $placeholders = [];
        foreach ($values as $index => $value) {
            $placeholders[] = ':' . $name . $index;
            $qb->setParameter($name . $index, $value);
        }
        $qb->andWhere(RollupSql::exactIn($platform, $column, $placeholders));
    }

    /** @param list<string> $ids */
    private function credential(QueryBuilder $qb, AbstractPlatform $platform, string $kindColumn, string $idColumn, ?string $kind, array $ids): void
    {
        if ($kind === null) {
            return;
        }
        $qb->andWhere(RollupSql::exact($platform, $kindColumn, ':credentialKind'))->setParameter('credentialKind', $kind);
        $this->in($qb, $idColumn, $ids, 'credentialIds', $platform);
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $groupBy
     */
    private function dailyUsage(array $row, array $groupBy): DailyUsage
    {
        $key = static function (string $column) use ($row, $groupBy): ?string {
            if (!in_array($column, $groupBy, true)) {
                return null;
            }
            $value = (string) $row[$column];

            return $value === '' ? null : $value;
        };
        $histogram = [];
        foreach ([...RollupTables::HISTOGRAM, RollupTables::OVERFLOW_COLUMN] as $column) {
            $histogram[substr($column, 2)] = $this->int($row[$column]);
        }
        $day = $key('day');

        return new DailyUsage(
            $day === null ? null : new DateTimeImmutable(substr($day, 0, 10), new DateTimeZone('UTC')),
            $key('channel'),
            $key('provider'),
            $key('credential_kind'),
            $key('credential_id'),
            $key('tenant_key'),
            $this->int($row['calls']),
            $this->int($row['errors']),
            $this->int($row['transport_errors']),
            $this->int($row['status_2xx']),
            $this->int($row['status_3xx']),
            $this->int($row['status_4xx']),
            $this->int($row['status_5xx']),
            $this->int($row['retries']),
            $this->int($row['bytes']),
            $this->int($row['rows_stored']),
            $this->int($row['duration_ms_sum']),
            $this->int($row['duration_ms_max']),
            $histogram,
            $this->time($row['first_seen_at']),
            $this->time($row['last_seen_at']),
        );
    }

    /** @param array<string, mixed> $row */
    private function usageCall(array $row): UsageCall
    {
        return new UsageCall(
            $this->int($row['id']),
            $this->str($row['requestId']),
            (string) $row['channel'],
            $this->str($row['provider']),
            (string) $row['method'],
            $this->str($row['scheme']),
            $this->str($row['host']),
            (string) $row['path'],
            $this->str($row['routeName']),
            $this->nullableInt($row['statusCode']),
            $this->str($row['errorKind']),
            is_numeric($row['durationMs']) ? (float) $row['durationMs'] : null,
            $this->nullableInt($row['responseBytes']),
            $this->nullableInt($row['retryCount']),
            $this->str($row['contentType']),
            $this->str($row['clientIp']),
            $this->str($row['userIdentifier']),
            $this->str($row['credentialKind']),
            $this->str($row['credentialId']),
            $this->str($row['tenantKey']),
            $this->str($row['projectKey']),
            $this->str($row['messengerMessageId']),
            $this->str($row['externalMessageId']),
            new DateTimeImmutable((string) $row['createdAt']),
        );
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** A stored UTC time (`Y-m-d H:i:s`). */
    private function time(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable(substr($value, 0, 19), new DateTimeZone('UTC')) : null;
    }
}
