<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Doctrine\PathPrefixColumnListener;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function dirname;
use function is_array;
use function ksort;

use const PHP_VERSION_ID;

/**
 * 1.24 (DDD host, B3): the shipped `HttpLogEntry.orm.xml` (validated against Doctrine's XSD) describes exactly what the
 * entity attributes do: same table, columns, types, options, indexes, id generator and repository, and therefore the
 * same column declarations and indexes on every platform, before and after the bundle's metadata listeners.
 */
final class XmlMappingParityTest extends TestCase
{
    #[Test]
    public function theXmlMappingMatchesTheAttributes(): void
    {
        $attribute = $this->metadata(new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']));
        $xml       = $this->metadata($this->xmlDriver());

        self::assertSame($attribute->getTableName(), $xml->getTableName());
        self::assertSame('nowo_http_log_entry', $xml->getTableName());
        self::assertSame($attribute->table, $xml->table);
        self::assertSame($attribute->getIdentifier(), $xml->getIdentifier());
        self::assertSame($attribute->generatorType, $xml->generatorType);
        self::assertSame($attribute->customRepositoryClassName, $xml->customRepositoryClassName);
        self::assertSame($this->fields($attribute), $this->fields($xml));
        self::assertSame([], $xml->associationMappings);
    }

    #[Test]
    public function bothMappingsGenerateTheSameSchema(): void
    {
        foreach ([[], ['app_', 'http_log_']] as $prefixes) {
            $attribute = $this->createTableSql(new AttributeDriver([dirname(__DIR__, 3) . '/src/Entity']), $prefixes);
            $xml       = $this->createTableSql($this->xmlDriver(), $prefixes);

            self::assertSame($attribute, $xml);
            self::assertNotSame([], $attribute);
        }
    }

    private function xmlDriver(): SimplifiedXmlDriver
    {
        // what DoctrineBundle registers for `mapping_type: xml` (type xml, dir, prefix)
        return new SimplifiedXmlDriver([NowoHttpLogExtension::XML_MAPPING_DIR => 'Nowo\\HttpLogBundle\\Entity']);
    }

    /** @return ClassMetadata<HttpLogEntry> */
    private function metadata(MappingDriver $driver): ClassMetadata
    {
        return $this->entityManager($driver)->getClassMetadata(HttpLogEntry::class);
    }

    /**
     * Column declarations (per platform, by column name: the XML driver maps the id after the fields, a column order
     * the schema comparator ignores), indexes and primary key of every table.
     *
     * @param list<string> $prefixes [table_prefix, table_name_prefix] of TablePrefixListener, none when empty
     *
     * @return array<string, array<string, mixed>>
     */
    private function createTableSql(MappingDriver $driver, array $prefixes): array
    {
        $manager = $this->entityManager($driver);
        // the bundle's listeners apply to either driver (here: prefix filters and table naming)
        $manager->getEventManager()->addEventListener('loadClassMetadata', new PathPrefixColumnListener());
        if ($prefixes !== []) {
            $manager->getEventManager()->addEventListener('loadClassMetadata', new TablePrefixListener($prefixes[0], $prefixes[1]));
        }
        $schema    = (new SchemaTool($manager))->getSchemaFromMetadata([$manager->getClassMetadata(HttpLogEntry::class)]);
        $platforms = ['sqlite' => $manager->getConnection()->getDatabasePlatform(), 'mysql' => new MySQL80Platform(), 'postgresql' => new PostgreSQLPlatform()];
        $sql       = [];
        foreach ($schema->getTables() as $table) {
            $name    = $table->getName();
            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexes[$index->getName()] = [$index->getColumns(), $index->isUnique(), $index->isPrimary()];
            }
            ksort($indexes);
            $sql[$name]['indexes'] = $indexes;
            foreach ($platforms as $platformName => $platform) {
                /** @var AbstractPlatform $platform */
                $columns = [];
                foreach ($table->getColumns() as $column) {
                    $columns[$column->getName()] = $platform->getColumnDeclarationSQL($column->getName(), $column->toArray());
                }
                ksort($columns);
                $sql[$name][$platformName] = $columns;
            }
        }

        return $sql;
    }

    /**
     * @param ClassMetadata<HttpLogEntry> $metadata
     *
     * @return array<string, array<string, mixed>>
     */
    private function fields(ClassMetadata $metadata): array
    {
        $fields = [];
        foreach ($metadata->fieldMappings as $name => $mapping) {
            $field = (array) $mapping;
            // the attribute driver writes false where the XML driver leaves null (same meaning), and only it numbers ids
            foreach (['unique', 'index', 'nullable'] as $flag) {
                $field[$flag] = (bool) ($field[$flag] ?? false);
            }
            unset($field['idPosition']);
            // a boolean column default: false (attribute) and "0" (XML) are the same DEFAULT 0
            if (is_array($field['options'] ?? null) && array_key_exists('default', $field['options']) && $field['type'] === 'boolean') {
                $field['options']['default'] = (string) (int) (bool) $field['options']['default'];
            }
            $fields[$name] = $field;
        }
        ksort($fields);

        return $fields;
    }

    private function entityManager(MappingDriver $driver): EntityManager
    {
        $configuration = ORMSetup::createConfiguration(true);
        $configuration->setMetadataDriverImpl($driver);
        $this->lazyObjects($configuration);

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration);
    }

    private function lazyObjects(Configuration $configuration): void
    {
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
    }
}
