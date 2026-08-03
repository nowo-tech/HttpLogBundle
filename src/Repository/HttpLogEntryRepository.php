<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;

use function array_key_exists;
use function is_string;

/**
 * @extends ServiceEntityRepository<HttpLogEntry>
 */
final class HttpLogEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HttpLogEntry::class);
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
