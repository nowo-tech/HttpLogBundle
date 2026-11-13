<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Nowo\HttpLogBundle\Doctrine\ExactColumnsCollationListener;
use Nowo\HttpLogBundle\Doctrine\PathPrefixColumnListener;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\Doctrine\UsageColumnsListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Rollup\RollupSchemaListener;
use Nowo\HttpLogBundle\Rollup\RollupTables;
use Psr\Log\LoggerInterface;

use function dirname;

use const PHP_VERSION_ID;

/**
 * In-memory SQLite database of the entry, with or without the HL-1 usage columns and the rollup tables, behind a
 * minimal ManagerRegistry.
 */
final readonly class UsageTestDatabase implements ManagerRegistry
{
    public EntityManager $entityManager;

    public RollupTables $rollupTables;

    /**
     * @param array<string, mixed> $connectionParams DBAL parameters (default: in-memory SQLite)
     */
    public function __construct(
        bool $usageColumns = true,
        string $tablePrefix = '',
        string $tableNamePrefix = TablePrefixListener::DEFAULT_TABLE_NAME_PREFIX,
        array $connectionParams = ['driver' => 'pdo_sqlite', 'memory' => true],
        bool $createSchema = true,
        bool $rollup = false,
        // web_ui.filter_match: prefix (maps path_prefix)
        bool $pathPrefix = false,
        // REQ-SEC-006 (1.20.1): the binary NO PAD collation of the identifier columns (false: the 1.20.0 schema)
        bool $exactCollation = true,
        ?LoggerInterface $logger = null,
    ) {
        $events             = new EventManager();
        $this->rollupTables = RollupTables::fromPrefixes($tablePrefix, $tableNamePrefix);
        if ($rollup) {
            $events->addEventListener(ToolEvents::postGenerateSchema, new RollupSchemaListener($this->rollupTables));
        }
        if ($exactCollation) {
            $events->addEventListener(ToolEvents::postGenerateSchemaTable, new ExactColumnsCollationListener($this, $logger));
        }
        if ($usageColumns) {
            $events->addEventListener(Events::loadClassMetadata, new UsageColumnsListener());
        }
        if ($pathPrefix) {
            $events->addEventListener(Events::loadClassMetadata, new PathPrefixColumnListener());
        }
        if ($tablePrefix !== '' || $tableNamePrefix !== TablePrefixListener::DEFAULT_TABLE_NAME_PREFIX) {
            $events->addEventListener(Events::loadClassMetadata, new TablePrefixListener($tablePrefix, $tableNamePrefix, $usageColumns));
        }

        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $connection          = DriverManager::getConnection($connectionParams, $configuration);
        $this->entityManager = new EntityManager($connection, $configuration, $events);
        if ($createSchema) {
            (new SchemaTool($this->entityManager))->createSchema([$this->entityManager->getClassMetadata(HttpLogEntry::class)]);
        }
    }

    public function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    public function repository(): HttpLogEntryRepository
    {
        return new HttpLogEntryRepository($this);
    }

    public function table(): string
    {
        return $this->entityManager->getClassMetadata(HttpLogEntry::class)->getTableName();
    }

    /** @return list<array<string, mixed>> every row, every column */
    public function rows(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection()->fetchAllAssociative('SELECT * FROM ' . $this->table() . ' ORDER BY id');

        return $rows;
    }

    public function getDefaultConnectionName(): string
    {
        return 'default';
    }

    public function getConnection(?string $name = null): Connection
    {
        return $this->connection();
    }

    public function getConnections(): array
    {
        return ['default' => $this->connection()];
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
