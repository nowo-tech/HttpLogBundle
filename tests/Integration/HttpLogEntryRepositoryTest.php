<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use InvalidArgumentException;
use LogicException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function array_map;
use function dirname;
use function sprintf;

use const PHP_VERSION_ID;

final class HttpLogEntryRepositoryTest extends TestCase
{
    private EntityManager $entityManager;
    private HttpLogEntryRepository $repository;

    protected function setUp(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__, 2) . '/src/Entity'],
            true,
        );
        // On PHP 8.4+, enable native lazy objects: Symfony 8 removed
        // ProxyHelper::generateLazyGhost, and Doctrine ORM 3 deprecates the old path.
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $configuration);

        $this->entityManager = new EntityManager($connection, $configuration);
        $schemaTool          = new SchemaTool($this->entityManager);
        $schemaTool->createSchema([
            $this->entityManager->getClassMetadata(HttpLogEntry::class),
        ]);

        $this->repository = new HttpLogEntryRepository(
            new TestManagerRegistry($this->entityManager, $connection),
        );
    }

    #[Test]
    public function findFilteredAppliesCriteriaAndPaginatesResults(): void
    {
        $newestMatch = $this->persistEntry(
            method: 'GET',
            routeName: 'api.match.index',
            statusCode: 200,
            clientIp: '10.0.0.1',
            path: '/api/match/newest',
            bodyContentType: 'json',
            createdAt: new DateTimeImmutable('2026-08-03T12:00:00+00:00'),
        );
        $olderMatch = $this->persistEntry(
            method: 'GET',
            routeName: 'api.match.detail',
            statusCode: 200,
            clientIp: '10.0.0.1',
            path: '/api/match/older',
            bodyContentType: 'json',
            createdAt: new DateTimeImmutable('2026-08-02T12:00:00+00:00'),
        );
        $this->persistEntry(
            method: 'POST',
            routeName: 'api.other',
            statusCode: 500,
            clientIp: '10.0.0.2',
            path: '/api/other',
            bodyContentType: 'html',
            createdAt: new DateTimeImmutable('2026-08-01T12:00:00+00:00'),
        );

        $criteria = [
            'method'          => 'get',
            'routeName'       => 'match',
            'statusCode'      => '200',
            'clientIp'        => '10.0.0.1',
            'path'            => '/api/match',
            'bodyContentType' => 'json',
            'createdFrom'     => new DateTimeImmutable('2026-08-02T00:00:00+00:00'),
            'createdTo'       => new DateTimeImmutable('2026-08-03T23:59:59+00:00'),
            'q'               => 'match',
        ];

        $firstPage  = $this->repository->findFiltered($criteria, 1, 1);
        $secondPage = $this->repository->findFiltered($criteria, 2, 1);

        self::assertSame(2, $firstPage['total']);
        self::assertCount(1, $firstPage['items']);
        self::assertSame($newestMatch->getId(), $firstPage['items'][0]->getId());

        self::assertSame(2, $secondPage['total']);
        self::assertCount(1, $secondPage['items']);
        self::assertSame($olderMatch->getId(), $secondPage['items'][0]->getId());
    }

    #[Test]
    public function findListPageCapsTheCountAndClampsThePage(): void
    {
        foreach (range(1, 5) as $i) {
            $this->persistEntry('GET', 'r' . $i, 200, '10.0.0.' . $i, '/p' . $i, 'json', new DateTimeImmutable('2026-08-0' . $i . 'T10:00:00+00:00'));
        }
        $this->entityManager->flush();

        $capped = $this->repository->findListPage([], 99, 2, maxListed: 3);
        self::assertSame(3, $capped['total']);
        self::assertTrue($capped['total_capped']);
        self::assertSame(2, $capped['page'], 'clamped to the last page of the capped list');
        self::assertCount(1, $capped['items']);

        $all = $this->repository->findListPage([], 1, 10);
        self::assertSame(5, $all['total']);
        self::assertFalse($all['total_capped']);
        self::assertSame('/p5', $all['items'][0]->getPath(), 'newest first');

        $empty = $this->repository->findListPage(['statusCode' => '599'], 4, 10);
        self::assertSame([0, 1, []], [$empty['total'], $empty['page'], $empty['items']]);
    }

    #[Test]
    public function findFilteredCanSkipTheCount(): void
    {
        $this->persistEntry('GET', 'r', 200, '10.0.0.1', '/p', 'json', new DateTimeImmutable('2026-08-01T10:00:00+00:00'));
        $this->entityManager->flush();

        $page = $this->repository->findFiltered([], 1, 10, withTotal: false);
        self::assertSame(0, $page['total']);
        self::assertCount(1, $page['items']);
    }

    #[Test]
    public function findFilteredListsEntriesOfTheSameSecondNewestIdFirst(): void
    {
        $at     = new DateTimeImmutable('2026-08-03T12:00:00+00:00');
        $first  = $this->persistEntry('GET', 'r', 200, '10.0.0.1', '/a', 'json', $at);
        $second = $this->persistEntry('GET', 'r', 200, '10.0.0.1', '/b', 'json', $at);
        $third  = $this->persistEntry('GET', 'r', 200, '10.0.0.1', '/c', 'json', $at);

        $ids = array_map(static fn (HttpLogEntry $entry): ?int => $entry->getId(), $this->repository->findFiltered([], 1, 10)['items']);

        self::assertSame([$third->getId(), $second->getId(), $first->getId()], $ids);
    }

    #[Test]
    public function deleteAndCountOperationsWorkForAllRepositoryHelpers(): void
    {
        $oldEntry = $this->persistEntry(
            method: 'GET',
            routeName: 'alpha.route',
            statusCode: 200,
            clientIp: '10.0.0.1',
            path: '/alpha',
            bodyContentType: 'json',
            createdAt: new DateTimeImmutable('2026-07-01T12:00:00+00:00'),
        );
        $recentEntry = $this->persistEntry(
            method: 'GET',
            routeName: 'beta.route',
            statusCode: 201,
            clientIp: '10.0.0.2',
            path: '/beta',
            bodyContentType: 'xml',
            createdAt: new DateTimeImmutable('2026-08-02T12:00:00+00:00'),
        );
        $keepEntry = $this->persistEntry(
            method: 'POST',
            routeName: 'gamma.route',
            statusCode: 204,
            clientIp: '10.0.0.3',
            path: '/gamma',
            bodyContentType: 'html',
            createdAt: new DateTimeImmutable('2026-08-03T12:00:00+00:00'),
        );

        self::assertSame(3, $this->repository->countAll());
        self::assertSame(2, $this->repository->countFiltered(['method' => 'get']));
        self::assertSame(1, $this->repository->countFiltered(['q' => '10.0.0.3']));
        self::assertSame(0, $this->repository->deleteByIds([]));

        self::assertSame(1, $this->repository->deleteOlderThan(new DateTimeImmutable('2026-08-01T00:00:00+00:00')));
        $this->entityManager->clear();
        self::assertNull($this->repository->find($oldEntry->getId()));

        self::assertSame(1, $this->repository->deleteByIds([(int) $recentEntry->getId()]));
        $this->entityManager->clear();
        self::assertNull($this->repository->find($recentEntry->getId()));

        self::assertSame(1, $this->repository->countAll());
        self::assertSame($keepEntry->getId(), $this->repository->findFiltered([], 1, 10)['items'][0]->getId());
    }

    #[Test]
    public function deleteOlderThanDeletesInKeysetBatches(): void
    {
        $keep = [];
        for ($i = 0; $i < 7; ++$i) {
            $this->persistEntry('GET', 'old.route', 200, '10.0.0.1', '/old/' . $i, 'json', new DateTimeImmutable('2026-07-01T12:00:00+00:00'));
            if ($i % 3 === 0) {
                $keep[] = $this->persistEntry('GET', 'new.route', 200, '10.0.0.1', '/new/' . $i, 'json', new DateTimeImmutable('2026-08-02T12:00:00+00:00'));
            }
        }

        $before = new DateTimeImmutable('2026-08-01T00:00:00+00:00');

        // 7 old rows, batches of 3: 3 + 3 + 1 (short batch stops the loop).
        self::assertSame(7, $this->repository->deleteOlderThan($before, 3));
        $this->entityManager->clear();
        self::assertSame(3, $this->repository->countAll());
        self::assertSame(0, $this->repository->deleteOlderThan($before, 3));

        // Exact multiple of the batch size: the last full batch is followed by one empty select.
        for ($i = 0; $i < 4; ++$i) {
            $this->persistEntry('GET', 'old.route', 200, '10.0.0.1', '/old2/' . $i, 'json', new DateTimeImmutable('2026-07-01T12:00:00+00:00'));
        }
        self::assertSame(4, $this->repository->deleteOlderThan($before, 2));
        self::assertSame(3, $this->repository->deleteOlderThan(new DateTimeImmutable('2026-09-01T00:00:00+00:00')));
        self::assertSame(0, $this->repository->countAll());
        self::assertCount(3, $keep);
    }

    #[Test]
    public function deleteOlderThanRejectsNonPositiveBatchSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->deleteOlderThan(new DateTimeImmutable(), 0);
    }

    #[Test]
    public function findOneByAndDetachAllUseResolvedEntityManager(): void
    {
        $entry = $this->persistEntry(
            method: 'GET',
            routeName: 'api.one',
            statusCode: 200,
            clientIp: '10.0.0.9',
            path: '/api/one',
            bodyContentType: 'json',
            createdAt: new DateTimeImmutable('2026-09-24T12:00:00+00:00'),
        );

        $found = $this->repository->findOneBy(
            ['requestId' => $entry->getRequestId()],
            ['id' => 'DESC'],
        );
        self::assertInstanceOf(HttpLogEntry::class, $found);
        self::assertTrue($this->entityManager->contains($found));

        $this->repository->detachAll([$found]);
        self::assertFalse($this->entityManager->contains($found));
        self::assertSame(0, $this->entityManager->getUnitOfWork()->size());

        $getEntityManager = new ReflectionMethod(HttpLogEntryRepository::class, 'getEntityManager');
        self::assertSame($this->entityManager, $getEntityManager->invoke($this->repository));
    }

    #[Test]
    public function findOneByRejectsFieldNamesThatAreNotMapped(): void
    {
        $this->persistEntry('GET', 'api.one', 200, '10.0.0.1', '/api/one', 'json', new DateTimeImmutable('2026-09-24T12:00:00+00:00'));

        foreach ([
            'id = 1 OR 1 = 1 OR e.id'       => 1,
            'routeName IS NOT NULL OR e.id' => 1,
            'no_such_field'                 => 'x',
        ] as $field => $value) {
            try {
                $this->repository->findOneBy([$field => $value]);
                self::fail(sprintf('Field "%s" must be rejected.', $field));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('Unrecognized field', $e->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->repository->findOneBy(['method' => 'GET'], ['id DESC, (SELECT 1)' => 'ASC']);
    }

    #[Test]
    public function findOneBySupportsNullAndListValues(): void
    {
        $withRoute = $this->persistEntry('GET', 'api.one', 200, '10.0.0.1', '/api/one', 'json', new DateTimeImmutable('2026-09-24T12:00:00+00:00'));
        $withRoute->setRequestId(null);
        $this->entityManager->flush();

        $found = $this->repository->findOneBy(['requestId' => null]);
        self::assertSame($withRoute->getId(), $found?->getId());

        $found = $this->repository->findOneBy(['method' => ['POST', 'GET']]);
        self::assertSame($withRoute->getId(), $found?->getId());

        self::assertNull($this->repository->findOneBy(['method' => ['DELETE']]));
    }

    #[Test]
    public function findOneBySupportsAscendingStringOrder(): void
    {
        $first = $this->persistEntry(
            method: 'GET',
            routeName: 'api.same',
            statusCode: 200,
            clientIp: '10.0.0.10',
            path: '/api/same/first',
            bodyContentType: 'json',
            createdAt: new DateTimeImmutable('2026-09-24T12:00:00+00:00'),
        );
        $second = $this->persistEntry(
            method: 'GET',
            routeName: 'api.same',
            statusCode: 200,
            clientIp: '10.0.0.11',
            path: '/api/same/second',
            bodyContentType: 'json',
            createdAt: new DateTimeImmutable('2026-09-24T12:05:00+00:00'),
        );

        $found = $this->repository->findOneBy(
            ['routeName' => 'api.same'],
            ['id' => 'ASC'],
        );

        self::assertInstanceOf(HttpLogEntry::class, $found);
        self::assertSame($first->getId(), $found->getId());
        self::assertNotSame($second->getId(), $found->getId());
    }

    #[Test]
    public function resolveEntityManagerResetsClosedManager(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__, 2) . '/src/Entity'],
            true,
        );
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $configuration);

        $registry = new ResettableManagerRegistry(
            static fn (): EntityManager => new EntityManager($connection, $configuration),
        );
        $repository = new HttpLogEntryRepository($registry);
        (new SchemaTool($registry->current()))->createSchema([
            $registry->current()->getClassMetadata(HttpLogEntry::class),
        ]);

        $closed = $registry->current();
        $closed->close();
        self::assertFalse($closed->isOpen());

        self::assertSame(0, $repository->countAll());
        self::assertTrue($registry->current()->isOpen());
        self::assertNotSame($closed, $registry->current());
    }

    #[Test]
    public function resolveEntityManagerThrowsWhenManagerMissing(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $repository = new HttpLogEntryRepository($registry);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Could not find the entity manager');
        $repository->countAll();
    }

    #[Test]
    public function resolveEntityManagerThrowsWhenClosedManagerCannotBeReset(): void
    {
        $closed = $this->createMock(EntityManager::class);
        $closed->method('isOpen')->willReturn(false);

        $other = $this->createMock(EntityManager::class);
        $other->method('isOpen')->willReturn(true);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagerNames')->willReturn(['other' => 'other', 'default' => 'default']);
        $registry->method('getManager')->willReturnCallback(
            static fn (?string $name = null): EntityManager => $name === 'default' ? $closed : $other,
        );
        // resetManager still returns a non-EM so the open check fails after a matching name.
        $registry->method('resetManager')->willReturn($this->createMock(ObjectManager::class));

        $repository = new HttpLogEntryRepository($registry);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is closed and could not be reset');
        $repository->countAll();
    }

    private function persistEntry(
        string $method,
        string $routeName,
        int $statusCode,
        string $clientIp,
        string $path,
        string $bodyContentType,
        DateTimeImmutable $createdAt,
    ): HttpLogEntry {
        $entry = (new HttpLogEntry())
            ->setRequestId(bin2hex(random_bytes(8)))
            ->setMethod($method)
            ->setScheme('https')
            ->setHost('example.test')
            ->setPath($path)
            ->setRouteName($routeName)
            ->setQueryParams(['page' => '1'])
            ->setStatusCode($statusCode)
            ->setClientIp($clientIp)
            ->setContentType('application/json')
            ->setBodyContentType($bodyContentType)
            ->setDurationMs(12.5)
            ->setUserIdentifier('user@example.test')
            ->setRequestHeaders(['Accept' => ['application/json']])
            ->setResponseHeaders(['Content-Type' => ['application/json']])
            ->setRequestBody('{"request":true}')
            ->setResponseBody('{"response":true}')
            ->setRequestBodyTruncated(false)
            ->setResponseBodyTruncated(false)
            ->setResponseBodyStored(true)
            ->setCreatedAt($createdAt);

        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }
}

final readonly class TestManagerRegistry implements ManagerRegistry
{
    public function __construct(
        private EntityManager $entityManager,
        private Connection $connection,
    ) {
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection(?string $name = null): Connection
    {
        return $this->connection;
    }

    public function getConnections(): array
    {
        return ['default' => $this->connection];
    }

    public function getConnectionNames(): array
    {
        return ['default' => 'default'];
    }

    public function getDefaultManagerName(): string
    {
        return 'default';
    }

    public function getManager(?string $name = null): ObjectManager
    {
        return $this->entityManager;
    }

    public function getManagers(): array
    {
        return ['default' => $this->entityManager];
    }

    public function resetManager(?string $name = null): ObjectManager
    {
        return $this->entityManager;
    }

    public function getManagerNames(): array
    {
        return ['default' => 'default'];
    }

    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        return $this->entityManager->getRepository($persistentObject);
    }

    public function getManagerForClass(string $class): ?ObjectManager
    {
        return $class === HttpLogEntry::class ? $this->entityManager : null;
    }
}
