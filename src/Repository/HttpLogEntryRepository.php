<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_values;
use function is_string;
use function sprintf;

/**
 * Resolves the EntityManager from {@see ManagerRegistry} on every call so a closed manager
 * that was reset after a failed flush (FrankenPHP worker without kernel reset) is not reused.
 *
 * @extends ServiceEntityRepository<HttpLogEntry>
 */
final class HttpLogEntryRepository extends ServiceEntityRepository
{
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

    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        $qb = $this->createQueryBuilder('e')->setMaxResults(1);

        $index = 0;
        foreach ($criteria as $field => $value) {
            $param = 'findOneBy_' . $index++;
            $qb->andWhere(sprintf('e.%s = :%s', $field, $param))
                ->setParameter($param, $value);
        }

        if ($orderBy !== null) {
            foreach ($orderBy as $field => $direction) {
                $qb->addOrderBy('e.' . $field, $direction);
            }
        }

        /** @var HttpLogEntry|null $entry */
        $entry = $qb->getQuery()->getOneOrNullResult();

        return $entry;
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array{items: list<HttpLogEntry>, total: int}
     */
    public function findFiltered(array $criteria, int $page, int $pageSize): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.createdAt', 'DESC');

        $this->applyCriteria($qb, $criteria);

        $countQb = clone $qb;
        $total   = (int) $countQb
            ->select('COUNT(e.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

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
            ->orderBy('e.id', 'ASC')
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

    public function deleteOlderThan(DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->where('e.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
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

        if ($manager->isOpen()) {
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

    /** @param array<string, mixed> $criteria */
    private function applyCriteria(QueryBuilder $qb, array $criteria): void
    {
        if (isset($criteria['method']) && is_string($criteria['method']) && $criteria['method'] !== '') {
            $qb->andWhere('e.method = :method')
                ->setParameter('method', strtoupper($criteria['method']));
        }

        if (isset($criteria['routeName']) && is_string($criteria['routeName']) && $criteria['routeName'] !== '') {
            $qb->andWhere('e.routeName LIKE :routeName')
                ->setParameter('routeName', '%' . $criteria['routeName'] . '%');
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
            $qb->andWhere('e.path LIKE :path')
                ->setParameter('path', '%' . $criteria['path'] . '%');
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

        if (isset($criteria['q']) && is_string($criteria['q']) && $criteria['q'] !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    'e.path LIKE :q',
                    'e.routeName LIKE :q',
                    'e.clientIp LIKE :q',
                ),
            )->setParameter('q', '%' . $criteria['q'] . '%');
        }
    }
}
