<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Rollup;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Throwable;

/**
 * Adds the rollup tables to the schema of the EntityManager that maps {@see HttpLogEntry} (HL-1 §8.1, the pattern of
 * Messenger's Doctrine transport). Registered only while `rollup.enabled`, so other hosts get no schema diff.
 */
final readonly class RollupSchemaListener
{
    public function __construct(private RollupTables $tables)
    {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $event): void
    {
        $manager = $event->getEntityManager();
        $schema  = $event->getSchema();

        try {
            // the schema of this manager holds the entry table only when the manager maps the bundle entity
            $entryTable = $manager->getClassMetadata(HttpLogEntry::class)->getTableName();
        } catch (Throwable) {
            return;
        }
        if (!$schema->hasTable($entryTable)) {
            return;
        }

        // @igor-ignore - RollupTables is readonly: addTo() changes the event schema, not the service
        $this->tables->addTo($schema, $manager->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform);
    }
}
