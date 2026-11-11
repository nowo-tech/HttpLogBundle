<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function dirname;
use function implode;
use function ksort;

use const PHP_VERSION_ID;

/**
 * Golden schema names (REQ-ORM-002): from the `nowo_http_log.doctrine` configuration to the generated schema. With the
 * defaults the table, index and unique names stay byte-identical; `table_name_prefix` replaces the `nowo_http_log_`
 * part.
 */
final class SchemaNamesTest extends TestCase
{
    private const INDEXES = [
        'idx_http_log_client_ip'   => 'client_ip,created_at',
        'idx_http_log_created_at'  => 'created_at,id',
        'idx_http_log_route_name'  => 'route_name,created_at',
        'idx_http_log_status_code' => 'status_code,created_at',
    ];

    #[Test]
    public function defaultNamesAreUnchanged(): void
    {
        $expected = ['nowo_http_log_entry' => ['UNIQ_A43F86E4427EB8A5' => 'request_id'] + self::INDEXES];

        self::assertSame($expected, $this->schemaNames([]));
        self::assertSame($expected, $this->schemaNames(['table_name_prefix' => 'nowo_http_log_', 'table_prefix' => '']));
    }

    #[Test]
    public function tableNamePrefixRenamesTheTableOnly(): void
    {
        // Doctrine derives the request_id unique name from the table name, so it follows the table.
        self::assertSame(['http_log_entry' => ['UNIQ_6855A9F4427EB8A5' => 'request_id'] + self::INDEXES], $this->schemaNames(['table_name_prefix' => 'http_log_']));
    }

    #[Test]
    public function tablePrefixStillPrependsToTableAndIndexes(): void
    {
        self::assertSame(['app_nowo_http_log_entry' => [
            'UNIQ_FA816E21427EB8A5'        => 'request_id',
            'app_idx_http_log_client_ip'   => 'client_ip,created_at',
            'app_idx_http_log_created_at'  => 'created_at,id',
            'app_idx_http_log_route_name'  => 'route_name,created_at',
            'app_idx_http_log_status_code' => 'status_code,created_at',
        ]], $this->schemaNames(['table_prefix' => 'app_']));
    }

    /**
     * Loads the extension with the given options, wires the listener as the container would and lists the schema.
     *
     * @param array<string, string> $doctrine `nowo_http_log.doctrine` options
     *
     * @return array<string, array<string, string>>
     */
    private function schemaNames(array $doctrine): array
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        (new NowoHttpLogExtension())->load([['security' => ['allow_unauthenticated' => true], 'doctrine' => $doctrine]], $container);

        $events = new EventManager();
        if ($container->hasDefinition(TablePrefixListener::class)) {
            $events->addEventListener(Events::loadClassMetadata, new TablePrefixListener(
                (string) $container->getParameter('nowo_http_log.doctrine.table_prefix'),
                (string) $container->getParameter('nowo_http_log.doctrine.table_name_prefix'),
            ));
        }

        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $em         = new EntityManager($connection, $configuration, $events);
        $schema     = (new SchemaTool($em))->getSchemaFromMetadata([$em->getClassMetadata(HttpLogEntry::class)]);

        $names = [];
        foreach ($schema->getTables() as $table) {
            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                if (!$index->isPrimary()) {
                    $indexes[$index->getName()] = implode(',', $index->getColumns());
                }
            }
            ksort($indexes);
            $names[$table->getName()] = $indexes;
        }

        return $names;
    }
}
