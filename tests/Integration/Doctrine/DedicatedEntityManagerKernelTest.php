<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Tests\Fixtures\Entity\HostArticle;
use Nowo\HttpLogBundle\Tests\Kernel\UsageKernel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

use function array_map;
use function dirname;

/**
 * 1.24 (DDD host, B3/B10 XML leg): a host keeps the bundle's table off its domain manager. The entries live on a
 * dedicated manager and connection, mapped from the shipped XML (by the bundle, or by the host itself), while the
 * host's default manager maps only its own entities (here with Doctrine auto_mapping on). Recording, reading and
 * the host's manager stay apart; the services resetter is never called (kernel reset false).
 */
final class DedicatedEntityManagerKernelTest extends TestCase
{
    private const HTTP_LOG = [
        'async'         => false,
        'sampling_rate' => 1.0,
    ];

    #[Test]
    public function theBundleMapsItsXmlOnTheNamedManagerOnly(): void
    {
        $this->assertEntriesLiveOnTheDedicatedManager(
            ['doctrine' => ['mapping_type' => 'xml', 'entity_manager' => 'http_log']],
            [],
            'bundle-xml',
        );
    }

    #[Test]
    public function theHostMapsTheXmlItselfUnderItsOwnName(): void
    {
        // what Beacon does by hand today: its own mapping name, so the bundle only keeps auto_mapping off the entity
        $this->assertEntriesLiveOnTheDedicatedManager(
            ['doctrine' => ['register_mapping' => false, 'entity_manager' => 'http_log']],
            ['HttpLog' => ['type' => 'xml', 'dir' => NowoHttpLogExtension::XML_MAPPING_DIR, 'prefix' => 'Nowo\\HttpLogBundle\\Entity', 'is_bundle' => false]],
            'host-xml',
        );
    }

    #[Test]
    public function aSecondManagerMappingTheEntriesFailsTheBuild(): void
    {
        $kernel = $this->kernel(
            ['doctrine' => ['entity_manager' => 'http_log']],
            [],
            ['NowoHttpLogBundleAgain' => ['type' => 'attribute', 'dir' => dirname(__DIR__, 3) . '/src/Entity', 'prefix' => 'Nowo\\HttpLogBundle\\Entity', 'is_bundle' => false]],
            'double-mapping',
        );

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('are mapped on: default, http_log');
            $kernel->boot();
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @param array<string, mixed> $httpLog nowo_http_log configuration
     * @param array<string, mixed> $hostMappings mappings the host adds on the dedicated manager
     */
    private function assertEntriesLiveOnTheDedicatedManager(array $httpLog, array $hostMappings, string $variant): void
    {
        $kernel = $this->kernel($httpLog, $hostMappings, [], $variant);
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $registry = $container->get('doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $registry);
            $default = $registry->getManager('default');
            $logs    = $registry->getManager('http_log');
            self::assertInstanceOf(EntityManagerInterface::class, $default);
            self::assertInstanceOf(EntityManagerInterface::class, $logs);

            self::assertSame($logs, $registry->getManagerForClass(HttpLogEntry::class));
            self::assertTrue($default->getMetadataFactory()->isTransient(HttpLogEntry::class), 'the host manager never maps the entries');
            self::assertSame([HttpLogEntry::class], array_map(static fn (ClassMetadata $m): string => $m->getName(), $logs->getMetadataFactory()->getAllMetadata()), 'the dedicated manager maps the entries alone');
            self::assertSame([HostArticle::class], array_map(static fn (ClassMetadata $m): string => $m->getName(), $default->getMetadataFactory()->getAllMetadata()));

            (new SchemaTool($default))->createSchema($default->getMetadataFactory()->getAllMetadata());
            (new SchemaTool($logs))->createSchema($logs->getMetadataFactory()->getAllMetadata());
            self::assertFalse($default->getConnection()->createSchemaManager()->tablesExist(['nowo_http_log_entry']), 'no bundle table on the host database');

            // the host leaves a pending change on its manager; recording at terminate never flushes it
            $default->persist(new HostArticle('pending'));
            foreach (['/hello', '/hello'] as $path) {
                $request  = Request::create($path, 'GET', [], [], [], ['REMOTE_ADDR' => '127.0.0.1']);
                $response = $kernel->handle($request);
                self::assertSame(200, $response->getStatusCode());
                $kernel->terminate($request, $response);
            }

            self::assertSame(2, (int) $logs->getConnection()->fetchOne('SELECT COUNT(*) FROM nowo_http_log_entry'));
            self::assertSame(0, (int) $default->getConnection()->fetchOne('SELECT COUNT(*) FROM host_article'));
            self::assertCount(1, $default->getUnitOfWork()->getScheduledEntityInsertions());

            $repository = $container->get(HttpLogEntryRepository::class);
            self::assertInstanceOf(HttpLogEntryRepository::class, $repository);
            self::assertSame(2, $repository->countAll());
            self::assertSame($logs->getConnection(), $repository->connection());
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * Two in-memory SQLite connections: `default` (the host's, auto_mapping on, the host entity) and `logs`.
     *
     * @param array<string, mixed> $httpLog
     * @param array<string, mixed> $logMappings mappings the host adds on the dedicated manager
     * @param array<string, mixed> $defaultMappings mappings the host adds on its default manager
     */
    private function kernel(array $httpLog, array $logMappings, array $defaultMappings, string $variant): UsageKernel
    {
        $doctrine = [
            'dbal' => [
                'default_connection' => 'default',
                'connections'        => [
                    'default' => ['url' => 'sqlite:///:memory:'],
                    'logs'    => ['url' => 'sqlite:///:memory:'],
                ],
            ],
            'orm' => [
                'default_entity_manager' => 'default',
                'entity_managers'        => [
                    'default' => [
                        'connection'   => 'default',
                        'auto_mapping' => true,
                        'mappings'     => ['HostFixtures' => ['type' => 'attribute', 'dir' => dirname(__DIR__, 2) . '/Fixtures/Entity', 'prefix' => 'Nowo\\HttpLogBundle\\Tests\\Fixtures\\Entity', 'is_bundle' => false]] + $defaultMappings,
                    ],
                    'http_log' => ['connection' => 'logs', 'mappings' => $logMappings],
                ],
            ],
        ];

        return new UsageKernel(self::HTTP_LOG + $httpLog, [], static function (ContainerBuilder $container): void {
            $container->register('logger', NullLogger::class)->setPublic(true);
        }, 'dedicated-em-' . $variant, $doctrine);
    }
}
