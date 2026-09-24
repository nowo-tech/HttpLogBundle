<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

use function dirname;
use function str_repeat;

use const PHP_VERSION_ID;

/**
 * Simulates consecutive requests handled by the same service instances with no kernel reset
 * (FrankenPHP worker mode without services_resetter).
 */
final class HttpLogRecorderWorkerModeTest extends TestCase
{
    private Configuration $configuration;
    private Connection $connection;
    private ResettableManagerRegistry $registry;

    protected function setUp(): void
    {
        $this->configuration = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__, 2) . '/src/Entity'],
            true,
        );
        if (PHP_VERSION_ID >= 80400) {
            $this->configuration->enableNativeLazyObjects(true);
        }
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $this->configuration);

        $this->registry = new ResettableManagerRegistry(
            fn (): EntityManager => new EntityManager($this->connection, $this->configuration),
        );
        $this->createSchema();
    }

    #[Test]
    public function consecutiveRequestsDoNotGrowTheIdentityMap(): void
    {
        $recorder = $this->createRecorder();

        foreach (['/first', '/second', '/third'] as $index => $path) {
            $recorder->record(Request::create($path), new Response('ok'), 1.0, 'req-' . $index);

            self::assertSame(0, $this->registry->current()->getUnitOfWork()->size());
        }

        self::assertSame(3, $this->repository()->countAll());
    }

    #[Test]
    public function failedFlushInOneRequestDoesNotBreakTheNextRequest(): void
    {
        $recorder = $this->createRecorder(['user' => false, 'max_body_bytes' => 65536]);
        $first    = $this->registry->current();

        $this->connection->executeStatement('DROP TABLE nowo_http_log_entry');
        $recorder->record(Request::create('/broken'), new Response('ok'), 1.0, 'req-broken');

        self::assertFalse($first->isOpen());
        self::assertNotSame($first, $this->registry->current());
        self::assertTrue($this->registry->current()->isOpen());

        $this->createSchema();
        $recorder->record(Request::create('/recovered'), new Response('ok'), 1.0, 'req-recovered');

        self::assertSame(1, $this->repository()->countAll());
    }

    #[Test]
    public function repositoryUsesResetManagerAfterClosedFlush(): void
    {
        $recorder   = $this->createRecorder(['user' => false, 'max_body_bytes' => 65536]);
        $repository = $this->repository();

        $this->connection->executeStatement('DROP TABLE nowo_http_log_entry');
        $recorder->record(Request::create('/broken'), new Response('ok'), 1.0, 'req-broken');

        $this->createSchema();
        $recorder->record(Request::create('/ok'), new Response('ok'), 1.0, 'req-ok');

        // Same repository instance must query through the reset manager (not a cached closed EM).
        self::assertSame(1, $repository->countAll());
        self::assertNotNull($repository->find($repository->findFiltered([], 1, 1)['items'][0]->getId()));
        $repository->detachAll($repository->findFiltered([], 1, 1)['items']);
        self::assertSame(0, $this->registry->current()->getUnitOfWork()->size());
    }

    #[Test]
    public function exportAndPurgeLeaveNoLoadedEntriesBehind(): void
    {
        $recorder = $this->createRecorder();
        $recorder->record(Request::create('/a', 'POST', content: str_repeat('x', 32)), new Response('ok'), 1.0, 'req-a');
        $recorder->record(Request::create('/b', 'DELETE'), new Response('ok'), 1.0, 'req-b');

        $repository = $this->repository();
        $entries    = $repository->findFiltered([], 1, 10)['items'];
        self::assertSame(2, $this->registry->current()->getUnitOfWork()->size());

        $repository->detachAll($entries);
        self::assertSame(0, $this->registry->current()->getUnitOfWork()->size());

        $ids = $repository->findIdsFiltered(['method' => 'DELETE'], 10);
        self::assertCount(1, $ids);
        self::assertSame(0, $this->registry->current()->getUnitOfWork()->size());
        self::assertSame(1, $repository->deleteByIds($ids));
        self::assertSame(1, $repository->countAll());
    }

    /**
     * @param array<string, mixed>|null $captureConfig
     */
    private function createRecorder(?array $captureConfig = null): HttpLogRecorder
    {
        $captureConfig ??= [
            'request_headers'  => true,
            'response_headers' => true,
            'request_body'     => true,
            'client_ip'        => true,
            'user'             => false,
            'max_body_bytes'   => 65536,
        ];

        return new HttpLogRecorder(
            $this->registry->current(),
            $this->createMock(MessageBusInterface::class),
            new MockClock(new DateTimeImmutable('2026-09-23T10:00:00+00:00')),
            new NullLogger(),
            new HttpLogRedactor([], [], []),
            new CapturePolicy($captureConfig),
            new ContentTypeClassifier(),
            false,
            $captureConfig,
            null,
            $this->registry,
        );
    }

    private function repository(): HttpLogEntryRepository
    {
        return new HttpLogEntryRepository($this->registry);
    }

    private function createSchema(): void
    {
        $entityManager = $this->registry->current();
        (new SchemaTool($entityManager))->createSchema([
            $entityManager->getClassMetadata(HttpLogEntry::class),
        ]);
    }
}

final class ResettableManagerRegistry implements ManagerRegistry
{
    private EntityManager $entityManager;

    /**
     * @param Closure(): EntityManager $factory
     */
    public function __construct(private readonly Closure $factory)
    {
        $this->entityManager = ($this->factory)();
    }

    public function current(): EntityManager
    {
        return $this->entityManager;
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection(?string $name = null): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function getConnections(): array
    {
        return ['default' => $this->entityManager->getConnection()];
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
        $this->entityManager = ($this->factory)();

        return $this->entityManager;
    }

    public function getManagerNames(): array
    {
        return ['default' => 'doctrine.orm.default_entity_manager'];
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
