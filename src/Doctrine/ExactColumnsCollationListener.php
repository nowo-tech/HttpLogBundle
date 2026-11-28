<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Doctrine;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Psr\Log\LoggerInterface;

use function method_exists;

/**
 * Exact-match collation for the identifier columns of the entry table on MySQL / MariaDB (REQ-SEC-006, since 1.20.1):
 * `user_identifier` and `client_ip` (the GDPR export / erasure keys) and, when the usage columns are mapped, `provider`,
 * `credential_kind`, `credential_id`, `tenant_key` and `project_key`, `error_code` while a provider configures it, and
 * `correlation_id` / `upstream_id` with `correlation.columns` (1.27: correlation ids are case-sensitive).
 *
 * The exporter, the eraser and the usage reader already re-check every row in PHP (`===`) or compare with
 * `CAST(… AS BINARY)`; the binary NO PAD collation ({@see ExactCollation}) keeps the database itself exact, so the admin
 * filters and the indexes do not fold `Bob`, `bób` or `bob ` onto `bob` either. Applied to the generated schema (schema
 * tool, `doctrine:migrations:diff`); existing tables need the `ALTER TABLE` of UPGRADING.
 *
 * 1.23.1 (audit XI): applied on `postGenerateSchema`, once the schema tool has added every foreign key, by table name.
 * 1.20.1–1.23.0 replaced the entry table on `postGenerateSchemaTable` (`setClassTable()`, DBAL 4.5+), and ORM 3.7–3.8
 * then added the table's foreign keys to the object it had replaced, so any foreign key starting on it (and its index)
 * was lost on MySQL / MariaDB (none exist today: latent). Now:
 *
 * - DBAL 4.5+: the table is rebuilt with its table editor (foreign keys and indexes included) and swapped into a new
 *   schema given back with `GenerateSchemaEventArgs::setSchema()`;
 * - older DBAL: the column's platform options are set in place (no editor exists there).
 *
 * Stateless (REQ-RUNTIME-002): the platform is the one of the schema tool's entity manager, read on each event; a
 * manager that does not map the entry is left alone.
 */
final readonly class ExactColumnsCollationListener
{
    /** Columns of the entry table that hold an identifier (those that are mapped get the collation). */
    public const COLUMNS = ['user_identifier', 'client_ip', 'provider', 'credential_kind', 'credential_id', 'tenant_key', 'project_key', 'error_code', 'correlation_id', 'upstream_id'];

    /**
     * @param ManagerRegistry|null $doctrine unused since 1.23.1 (the platform comes from the schema tool's entity
     *                                       manager); kept for BC
     */
    public function __construct(
        ?ManagerRegistry $doctrine = null,
        private ?LoggerInterface $logger = null,
    ) {
        unset($doctrine);
    }

    /**
     * Gives the identifier columns of the entry table their exact-match collation in the generated schema, keeping every
     * foreign key and index of the table.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $manager = $args->getEntityManager();
        if ($manager->getMetadataFactory()->isTransient(HttpLogEntry::class)) {
            return;
        }
        $schema = $args->getSchema();
        $name   = $manager->getClassMetadata(HttpLogEntry::class)->getTableName();
        if (!$schema->hasTable($name)) {
            return;
        }
        $collation = ExactCollation::forConnection($manager->getConnection(), $this->logger);
        if ($collation === null) {
            return;
        }

        $original = $table = $schema->getTable($name);
        // Table::edit() and Schema::edit() exist only from doctrine/dbal 4.5.
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType
        $editor = method_exists(Table::class, 'edit') && method_exists(Schema::class, 'edit');
        foreach (self::COLUMNS as $column) {
            if ($table->hasColumn($column)) {
                $table = ExactCollation::collate($table, $column, $collation, $editor) ?? $table;
            }
        }
        if ($table !== $original) {
            $args->setSchema($schema->edit()->dropTable($original->getObjectName())->addTable($table)->create());
        }
    }

    /**
     * No longer subscribed (1.23.1, audit XI): replacing the table there lost its foreign keys. Kept for BC; does nothing.
     *
     * @deprecated since 1.23.1, the collation is applied on postGenerateSchema ({@see self::postGenerateSchema()})
     */
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        unset($args);
    }
}
