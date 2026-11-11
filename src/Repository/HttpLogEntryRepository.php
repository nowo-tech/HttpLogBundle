<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use InvalidArgumentException;
use LogicException;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\Doctrine\PathPrefixColumnListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Model\HttpLogListCursor;
use Nowo\HttpLogBundle\Model\HttpLogListRow;
use Nowo\HttpLogBundle\Usage\HttpLogChannel;
use SortDirection;

use function array_diff;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_reverse;
use function array_values;
use function ceil;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function max;
use function mb_strlen;
use function min;
use function sprintf;
use function str_starts_with;
use function strtr;

/**
 * Resolves the EntityManager from {@see ManagerRegistry} on every call so a closed manager
 * that was reset after a failed flush (FrankenPHP worker without kernel reset) is not reused.
 *
 * @extends ServiceEntityRepository<HttpLogEntry>
 */
final class HttpLogEntryRepository extends ServiceEntityRepository
{
    /** Entries the admin list paginates and counts at most (deeper results: narrow the filters). */
    public const MAX_LISTED = 10000;

    /**
     * Up to this many matching entries, the list pages read them by primary key from the ids the count already
     * collected (PERF-1147-02): a planner that walks the created_at index for a few scattered matches would read most of
     * the table. Below SQLite's oldest bound-parameter limit (999).
     */
    public const SMALL_MATCH_SET = 500;

    /** HL-1 exact-match filters of the admin list (applied only while the usage columns are mapped). */
    public const USAGE_EXACT_FILTERS = ['provider', 'tenantKey', 'projectKey', 'credentialKind'];

    public function __construct(private readonly ManagerRegistry $registry)
    {
        parent::__construct($registry, HttpLogEntry::class);
    }

    public function createQueryBuilder(string $alias, ?string $indexBy = null): QueryBuilder
    {
        return $this->resolveEntityManager()->createQueryBuilder()
            ->select($alias)
            ->from(HttpLogEntry::class, $alias, $indexBy);
    }

    public function find(mixed $id, int|LockMode|null $lockMode = LockMode::NONE, ?int $lockVersion = null): ?object
    {
        return $this->resolveEntityManager()->find(HttpLogEntry::class, $id, $lockMode ?? LockMode::NONE, $lockVersion);
    }

    /**
     * Field names are checked against the entity metadata before they reach DQL (same contract as
     * Doctrine's EntityRepository: unknown fields throw), so a caller-controlled key cannot inject DQL.
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        $metadata = $this->resolveEntityManager()->getClassMetadata(HttpLogEntry::class);
        $qb       = $this->createQueryBuilder('e')->setMaxResults(1);

        $index = 0;
        foreach ($criteria as $field => $value) {
            $field = $this->assertKnownField($metadata, $field);
            if ($value === null) {
                $qb->andWhere(sprintf('e.%s IS NULL', $field));

                continue;
            }

            $param = 'findOneBy_' . $index++;
            $qb->andWhere(sprintf(is_array($value) ? 'e.%s IN (:%s)' : 'e.%s = :%s', $field, $param))
                ->setParameter($param, $value);
        }

        if ($orderBy !== null) {
            foreach ($orderBy as $field => $direction) {
                $field         = $this->assertKnownField($metadata, $field);
                $sortDirection = match (strtoupper($direction)) {
                    'DESC'  => SortDirection::Descending,
                    default => SortDirection::Ascending,
                };
                $qb->addOrderBy('e.' . $field, $sortDirection);
            }
        }

        /** @var HttpLogEntry|null $entry */
        $entry = $qb->getQuery()->getOneOrNullResult();

        return $entry;
    }

    /**
     * @param ClassMetadata<HttpLogEntry> $metadata
     */
    private function assertKnownField(ClassMetadata $metadata, int|string $field): string
    {
        $field = (string) $field;
        if (!$metadata->hasField($field) && !$metadata->hasAssociation($field)) {
            throw new InvalidArgumentException(sprintf('Unrecognized field "%s" for %s.', $field, HttpLogEntry::class));
        }

        return $field;
    }

    /**
     * Admin list page: at most {@see MAX_LISTED} matching entries are paginated and counted, so a huge log never costs a
     * full COUNT or a deep OFFSET per page view. `total_capped` is true when more entries match (narrow the filters).
     *
     * @deprecated since 1.17, use {@see findListRows()}: this one hydrates full entries (headers and bodies) and pages
     *             by OFFSET; it will be removed in 2.0
     *
     * @param array<string, mixed> $criteria
     * @param int $maxListed cap (defaults to {@see MAX_LISTED})
     *
     * @return array{items: list<HttpLogEntry>, total: int, total_capped: bool, page: int}
     */
    public function findListPage(array $criteria, int $page, int $pageSize, int $maxListed = self::MAX_LISTED): array
    {
        $pageSize = max(1, $pageSize);
        $countQb  = $this->createQueryBuilder('e')->select('e.id')->setMaxResults($maxListed + 1);
        $this->applyCriteria($countQb, $criteria);
        $matched = count($countQb->getQuery()->getScalarResult());
        $total   = min($matched, $maxListed);
        $page    = min(max(1, $page), max(1, (int) ceil($total / $pageSize)));

        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.createdAt', SortDirection::Descending)
            ->addOrderBy('e.id', SortDirection::Descending)
            ->setFirstResult(($page - 1) * $pageSize)
            // the last page of a capped list ends at the cap
            ->setMaxResults(max(1, min($pageSize, $total - ($page - 1) * $pageSize)));
        $this->applyCriteria($qb, $criteria);
        /** @var list<HttpLogEntry> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total, 'total_capped' => $matched > $maxListed, 'page' => $page];
    }

    /**
     * Admin list page (PERF-1147-02): a scalar projection of the listed columns ({@see HttpLogListRow}; never the query
     * string, headers or bodies) read in `(created_at, id)` order by keyset.
     *
     * At most {@see MAX_LISTED} matching entries are counted (`total_capped` = more match). Page 1 is the newest rows.
     * With the `$cursor` of the page the link was rendered from, page `n` seeks past that page's last (older pages) or
     * first (newer pages) row through the `created_at` index and skips only the pages in between: adjacent pages read
     * exactly one page of rows, whatever their depth. Without a usable cursor (a 1.16 `?page=` link, a stale cursor),
     * the page is read by OFFSET, bounded by the cap. Up to {@see SMALL_MATCH_SET} matches are read by primary key, and
     * no match reads no page. `cursor` in the result is the position of the returned page.
     *
     * @param array<string, mixed> $criteria
     * @param int $maxListed cap (defaults to {@see MAX_LISTED})
     *
     * @return array{items: list<HttpLogListRow>, total: int, total_capped: bool, page: int, cursor: HttpLogListCursor|null}
     */
    public function findListRows(array $criteria, int $page, int $pageSize, ?HttpLogListCursor $cursor = null, int $maxListed = self::MAX_LISTED): array
    {
        $pageSize = max(1, $pageSize);
        $countQb  = $this->createQueryBuilder('e')->select('e.id')->setMaxResults($maxListed + 1);
        $this->applyCriteria($countQb, $criteria);
        /** @var list<array{id: int|string}> $idRows */
        $idRows   = $countQb->getQuery()->getScalarResult();
        $ids      = array_map(static fn (array $row): int => (int) $row['id'], $idRows);
        $matched  = count($ids);
        $total    = min($matched, $maxListed);
        $lastPage = max(1, (int) ceil($total / $pageSize));
        $page     = min(max(1, $page), $lastPage);
        // the last page of a capped list ends at the cap
        $limit = max(1, min($pageSize, $total - ($page - 1) * $pageSize));
        // a few matches: read them by primary key (their ids are the whole match set) instead of a created_at walk
        $only = $matched <= min(self::SMALL_MATCH_SET, $maxListed) ? $ids : null;

        $items = null;
        if ($total === 0) {
            $items = [];
        } elseif ($page === 1) {
            $items = $this->listRows($criteria, $limit, only: $only);
        } elseif ($cursor instanceof HttpLogListCursor && $cursor->idsInRange() && $cursor->page <= $lastPage) {
            // a cursor past the last page of the bounded count is forged or stale (LU-L2): ignoring it keeps every skip
            // below the list cap in both directions
            $items = $this->seekListRows($criteria, $page, $pageSize, $limit, $cursor, $only);
        }
        // no cursor, or none of its rows left: offset from the newest row, bounded by the cap
        $items ??= $this->listRows($criteria, $limit, ($page - 1) * $pageSize, only: $only);

        return [
            'items'        => $items,
            'total'        => $total,
            'total_capped' => $matched > $maxListed,
            'page'         => $page,
            'cursor'       => HttpLogListCursor::fromRows($page, ...$items),
        ];
    }

    /**
     * Rows of page `$page` relative to the page `$cursor` was taken from; null when no row is found there (the caller
     * falls back to the offset read).
     *
     * @param array<string, mixed> $criteria
     * @param list<int>|null $only ids of the whole (small) match set
     *
     * @return list<HttpLogListRow>|null
     */
    private function seekListRows(array $criteria, int $page, int $pageSize, int $limit, HttpLogListCursor $cursor, ?array $only): ?array
    {
        if ($page >= $cursor->page) {
            // same page (reload): from its first row on; an older page: after its last row, skipping the pages between
            [$createdAt, $id, $inclusive, $skip] = $page === $cursor->page
                ? [$cursor->firstCreatedAt, $cursor->firstId, true, 0]
                : [$cursor->lastCreatedAt, $cursor->lastId, false, ($page - $cursor->page - 1) * $pageSize];
            $rows = $this->listRows($criteria, $limit, $skip, [$createdAt, $id, $inclusive ? '<=' : '<'], $only);
        } else {
            // a newer page: before its first row, read upwards (oldest first) and reversed
            $rows = array_reverse($this->listRows($criteria, $limit, ($cursor->page - $page - 1) * $pageSize, [$cursor->firstCreatedAt, $cursor->firstId, '>'], $only));
        }

        // none: the cursor points past the rows that still match (purged, filtered out)
        return $rows === [] ? null : $rows;
    }

    /**
     * One page of the projection, newest first (`>` reads oldest first): `$after` = [created_at, id, operator] is the
     * keyset bound (`<`, `<=` or `>` on the pair), `$offset` the rows skipped after it, `$only` the ids of a small match
     * set (read by primary key; the criteria still apply).
     *
     * @param array<string, mixed> $criteria
     * @param array{0: DateTimeImmutable, 1: int, 2: '<'|'<='|'>'}|null $after
     * @param list<int>|null $only
     *
     * @return list<HttpLogListRow>
     */
    private function listRows(array $criteria, int $limit, int $offset = 0, ?array $after = null, ?array $only = null): array
    {
        $fields = HttpLogListRow::FIELDS;
        if ($this->hasUsageColumns()) {
            $fields = array_merge($fields, HttpLogListRow::USAGE_FIELDS);
        }
        $direction = $after !== null && $after[2] === '>' ? SortDirection::Ascending : SortDirection::Descending;

        $qb = $this->createQueryBuilder('e')
            ->select(array_map(static fn (string $field): string => 'e.' . $field, $fields))
            ->orderBy('e.createdAt', $direction)
            ->addOrderBy('e.id', $direction)
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        if ($after !== null) {
            [$createdAt, $id, $operator] = $after;
            // (created_at, id) <op> (:c, :i), written so the created_at bound alone is an index range
            $qb->andWhere(match ($operator) {
                '<'  => 'e.createdAt <= :keysetAt AND (e.createdAt < :keysetAt OR e.id < :keysetId)',
                '<=' => 'e.createdAt <= :keysetAt AND (e.createdAt < :keysetAt OR e.id <= :keysetId)',
                '>'  => 'e.createdAt >= :keysetAt AND (e.createdAt > :keysetAt OR e.id > :keysetId)',
            })
                ->setParameter('keysetAt', $createdAt)
                ->setParameter('keysetId', $id);
        }
        if ($only !== null) {
            $qb->andWhere('e.id IN (:listIds)')->setParameter('listIds', $only);
        }
        $this->applyCriteria($qb, $criteria);

        /** @var list<array<string, mixed>> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        return array_map(HttpLogListRow::fromArray(...), $rows);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param bool $withTotal false skips the COUNT (batch exports read page after page)
     *
     * @return array{items: list<HttpLogEntry>, total: int}
     */
    public function findFiltered(array $criteria, int $page, int $pageSize, bool $withTotal = true): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.createdAt', SortDirection::Descending)
            // entries logged within the same second: newest id first (stable pages)
            ->addOrderBy('e.id', SortDirection::Descending);

        $this->applyCriteria($qb, $criteria);

        $total = 0;
        if ($withTotal) {
            $countQb = clone $qb;
            $total   = (int) $countQb
                ->select('COUNT(e.id)')
                ->resetDQLPart('orderBy')
                ->getQuery()
                ->getSingleScalarResult();
        }

        $items = $qb
            ->setFirstResult(max(0, ($page - 1) * $pageSize))
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return list<int>
     */
    public function findIdsFiltered(array $criteria, int $limit): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('e.id')
            ->orderBy('e.id', SortDirection::Ascending)
            ->setMaxResults($limit);

        $this->applyCriteria($qb, $criteria);

        return array_values(array_map(intval(...), $qb->getQuery()->getSingleColumnResult()));
    }

    /**
     * Detaches entries loaded in batches so they do not stay in a long-lived identity map.
     *
     * @param iterable<HttpLogEntry> $entries
     */
    public function detachAll(iterable $entries): void
    {
        $entityManager = $this->resolveEntityManager();

        foreach ($entries as $entry) {
            if ($entityManager->contains($entry)) {
                $entityManager->detach($entry);
            }
        }
    }

    /**
     * Deletes entries created before `$before` in keyset batches: select up to `$batchSize` ids greater than
     * the last one seen (ordered by id, platform limit), delete that id list, repeat until a short batch.
     * Each statement stays small, so a large log is never locked or written to the binlog in one go.
     *
     * `$channel` limits the purge to one channel (HL-1 §10): `web` = rows without a channel, `outbound`, `mailer`.
     * null keeps the 1.14 meaning (every row). Without the usage columns mapped every row is a web row, and another
     * channel has none.
     *
     * @return int Rows deleted
     */
    public function deleteOlderThan(DateTimeImmutable $before, int $batchSize = Configuration::DEFAULT_PURGE_BATCH_SIZE, ?string $channel = null): int
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException(sprintf('The purge batch size must be a positive integer, got %d.', $batchSize));
        }
        if ($channel !== null && $channel !== HttpLogChannel::WEB && !$this->hasUsageColumns()) {
            return 0;
        }

        $total   = 0;
        $afterId = 0;

        do {
            $qb = $this->createQueryBuilder('e')
                ->select('e.id')
                ->where('e.createdAt < :before')
                ->andWhere('e.id > :afterId')
                ->setParameter('before', $before)
                ->setParameter('afterId', $afterId)
                ->orderBy('e.id', SortDirection::Ascending)
                ->setMaxResults($batchSize);
            if ($channel !== null) {
                $this->applyCriteria($qb, ['channel' => $channel]);
            }
            /** @var list<int|string> $rows */
            $rows = $qb->getQuery()->getSingleColumnResult();

            $ids = array_map(intval(...), $rows);
            if ($ids === []) {
                break;
            }

            $total += $this->deleteByIds($ids);
            $afterId = $ids[count($ids) - 1];
        } while (count($ids) === $batchSize);

        return $total;
    }

    /** @param list<int> $ids */
    public function deleteByIds(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param array<string, mixed> $criteria */
    public function countFiltered(array $criteria): int
    {
        $qb = $this->createQueryBuilder('e');
        $this->applyCriteria($qb, $criteria);

        return (int) $qb
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** True when the HL-1 usage columns (channel, provider, tenant…) are mapped: outbound, mailer or a named channel enabled. */
    public function hasUsageColumns(): bool
    {
        return $this->resolveEntityManager()->getClassMetadata(HttpLogEntry::class)->hasField('channel');
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        return $this->resolveEntityManager();
    }

    private function resolveEntityManager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(HttpLogEntry::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('Could not find the entity manager for class "%s".', HttpLogEntry::class));
        }

        // A closed manager still answers DQL / DBAL reads. It is reset only when it holds the bundle's entities alone
        // (a dedicated manager): resetting a shared one would swap the host's manager under its own services.
        if ($manager->isOpen() || !$this->isDedicatedManager($manager)) {
            return $manager;
        }

        foreach (array_keys($this->registry->getManagerNames()) as $name) {
            if ($this->registry->getManager($name) !== $manager) {
                continue;
            }

            $reset = $this->registry->resetManager($name);
            if ($reset instanceof EntityManagerInterface) {
                return $reset;
            }
        }

        throw new LogicException(sprintf('The entity manager for class "%s" is closed and could not be reset.', HttpLogEntry::class));
    }

    /** True when every class the manager maps belongs to this bundle. */
    private function isDedicatedManager(EntityManagerInterface $manager): bool
    {
        $driver = $manager->getConfiguration()->getMetadataDriverImpl();
        if (!$driver instanceof MappingDriver) {
            return false;
        }

        foreach ($driver->getAllClassNames() as $className) {
            if (!str_starts_with($className, 'Nowo\\HttpLogBundle\\Entity\\')) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $criteria */
    private function applyCriteria(QueryBuilder $qb, array $criteria): void
    {
        if (isset($criteria['method']) && is_string($criteria['method']) && $criteria['method'] !== '') {
            $qb->andWhere('e.method = :method')
                ->setParameter('method', strtoupper($criteria['method']));
        }

        $prefix = $this->hasPathPrefixColumn();

        if (isset($criteria['routeName']) && is_string($criteria['routeName']) && $criteria['routeName'] !== '') {
            $qb->andWhere("e.routeName LIKE :routeName ESCAPE '!'")
                ->setParameter('routeName', $this->likePattern($criteria['routeName'], $prefix));
        }

        if (array_key_exists('statusCode', $criteria) && $criteria['statusCode'] !== '' && $criteria['statusCode'] !== null) {
            $qb->andWhere('e.statusCode = :statusCode')
                ->setParameter('statusCode', (int) $criteria['statusCode']);
        }

        if (isset($criteria['clientIp']) && is_string($criteria['clientIp']) && $criteria['clientIp'] !== '') {
            $qb->andWhere('e.clientIp = :clientIp')
                ->setParameter('clientIp', $criteria['clientIp']);
        }

        if (isset($criteria['path']) && is_string($criteria['path']) && $criteria['path'] !== '') {
            if ($prefix) {
                $qb->andWhere($this->pathPrefixCondition($qb, $criteria['path'], 'path'));
            } else {
                $qb->andWhere("e.path LIKE :path ESCAPE '!'")->setParameter('path', $this->likePattern($criteria['path'], false));
            }
        }

        if (isset($criteria['bodyContentType']) && is_string($criteria['bodyContentType']) && $criteria['bodyContentType'] !== '') {
            $qb->andWhere('e.bodyContentType = :bodyContentType')
                ->setParameter('bodyContentType', $criteria['bodyContentType']);
        }

        if (isset($criteria['createdFrom']) && $criteria['createdFrom'] instanceof DateTimeImmutable) {
            $qb->andWhere('e.createdAt >= :createdFrom')
                ->setParameter('createdFrom', $criteria['createdFrom']);
        }

        if (isset($criteria['createdTo']) && $criteria['createdTo'] instanceof DateTimeImmutable) {
            $qb->andWhere('e.createdAt <= :createdTo')
                ->setParameter('createdTo', $criteria['createdTo']);
        }

        if (isset($criteria['createdBefore']) && $criteria['createdBefore'] instanceof DateTimeImmutable) {
            $qb->andWhere('e.createdAt < :createdBefore')
                ->setParameter('createdBefore', $criteria['createdBefore']);
        }

        $this->applyUsageCriteria($qb, $criteria);

        if (isset($criteria['q']) && is_string($criteria['q']) && $criteria['q'] !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    $prefix ? $this->pathPrefixCondition($qb, $criteria['q'], 'q') : "e.path LIKE :q ESCAPE '!'",
                    "e.routeName LIKE :q ESCAPE '!'",
                    "e.clientIp LIKE :q ESCAPE '!'",
                ),
            )->setParameter('q', $this->likePattern($criteria['q'], $prefix));
        }
    }

    /**
     * Prefix match of the path on the indexed `path_prefix` column (parameter `:<param>_prefix`); a needle longer than
     * the column also checks the full path (parameter `:<param>`, the full prefix pattern).
     */
    private function pathPrefixCondition(QueryBuilder $qb, string $needle, string $param): string
    {
        $qb->setParameter($param . '_prefix', $this->likePattern(HttpLogEntry::pathPrefixOf($needle), true));
        $condition = sprintf("e.%s LIKE :%s_prefix ESCAPE '!'", PathPrefixColumnListener::FIELD, $param);
        if (mb_strlen($needle) <= HttpLogEntry::PATH_PREFIX_LENGTH) {
            return $condition;
        }
        $qb->setParameter($param, $this->likePattern($needle, true));

        return sprintf("(%s AND e.path LIKE :%s ESCAPE '!')", $condition, $param);
    }

    /**
     * LIKE pattern of a filter value: `%` and `_` typed by the user match literally (escape character `!`), then
     * `value%` (prefix, index range) or `%value%` (contains, scan).
     */
    private function likePattern(string $value, bool $prefix): string
    {
        $escaped = strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);

        return ($prefix ? '' : '%') . $escaped . '%';
    }

    /** `web_ui.filter_match: prefix` maps the `path_prefix` column ({@see PathPrefixColumnListener}). */
    public function hasPathPrefixColumn(): bool
    {
        return $this->resolveEntityManager()->getClassMetadata(HttpLogEntry::class)->hasField(PathPrefixColumnListener::FIELD);
    }

    /**
     * HL-1 filters. `channel` (`web` = rows without a channel) and the exact-match keys apply only while the usage
     * columns are mapped. `allowedChannels` (set by the admin when the access checker restricts channels) always
     * applies: without the usage columns every row is a web row.
     *
     * @param array<string, mixed> $criteria
     */
    private function applyUsageCriteria(QueryBuilder $qb, array $criteria): void
    {
        $mapped = $this->hasUsageColumns();

        if (isset($criteria['allowedChannels']) && is_array($criteria['allowedChannels'])) {
            $allowed = array_values(array_filter($criteria['allowedChannels'], is_string(...)));
            $withWeb = in_array(HttpLogChannel::WEB, $allowed, true);
            $others  = array_values(array_diff($allowed, [HttpLogChannel::WEB]));
            if (!$mapped) {
                if (!$withWeb) {
                    $qb->andWhere('1 = 0');
                }
            } elseif ($others === []) {
                $qb->andWhere($withWeb ? 'e.channel IS NULL' : '1 = 0');
            } else {
                $qb->andWhere($withWeb ? '(e.channel IS NULL OR e.channel IN (:allowedChannels))' : 'e.channel IN (:allowedChannels)')
                    ->setParameter('allowedChannels', $others);
            }
        }

        if (!$mapped) {
            return;
        }

        if (isset($criteria['channel']) && is_string($criteria['channel']) && $criteria['channel'] !== '') {
            if ($criteria['channel'] === HttpLogChannel::WEB) {
                $qb->andWhere('e.channel IS NULL');
            } else {
                $qb->andWhere('e.channel = :channel')->setParameter('channel', $criteria['channel']);
            }
        }

        foreach (self::USAGE_EXACT_FILTERS as $field) {
            if (isset($criteria[$field]) && is_string($criteria[$field]) && $criteria[$field] !== '') {
                $qb->andWhere(sprintf('e.%1$s = :%1$s', $field))->setParameter($field, $criteria[$field]);
            }
        }
    }
}
