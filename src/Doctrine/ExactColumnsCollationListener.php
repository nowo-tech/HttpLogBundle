<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Psr\Log\LoggerInterface;

use function method_exists;

/**
 * Exact-match collation for the identifier columns of the entry table on MySQL / MariaDB (REQ-SEC-006, since 1.20.1):
 * `user_identifier` and `client_ip` (the GDPR export / erasure keys) and, when the usage columns are mapped, `provider`,
 * `credential_kind`, `credential_id`, `tenant_key` and `project_key`.
 *
 * The exporter, the eraser and the usage reader already re-check every row in PHP (`===`) or compare with
 * `CAST(… AS BINARY)`; the binary NO PAD collation ({@see ExactCollation}) keeps the database itself exact, so the admin
 * filters and the indexes do not fold `Bob`, `bób` or `bob ` onto `bob` either. Applied to the generated schema (schema
 * tool, `doctrine:migrations:diff`); existing tables need the `ALTER TABLE` of UPGRADING.
 *
 * Stateless (REQ-RUNTIME-002): the platform is read from the registry on each event.
 */
final readonly class ExactColumnsCollationListener
{
    /** Columns of the entry table that hold an identifier (those that are mapped get the collation). */
    public const COLUMNS = ['user_identifier', 'client_ip', 'provider', 'credential_kind', 'credential_id', 'tenant_key', 'project_key'];

    public function __construct(
        private ManagerRegistry $doctrine,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        if ($args->getClassMetadata()->getName() !== HttpLogEntry::class) {
            return;
        }
        $manager   = $this->doctrine->getManagerForClass(HttpLogEntry::class);
        $collation = $manager instanceof EntityManagerInterface ? ExactCollation::forConnection($manager->getConnection(), $this->logger) : null;
        if ($collation === null) {
            return;
        }

        $original = $table = $args->getClassTable();
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType (Table::edit() from doctrine/dbal 4.5, setClassTable() from doctrine/orm 3.6)
        $editor = method_exists($table, 'edit') && method_exists($args, 'setClassTable');
        foreach (self::COLUMNS as $column) {
            if ($table->hasColumn($column)) {
                $table = ExactCollation::collate($table, $column, $collation, $editor) ?? $table;
            }
        }
        if ($table !== $original) {
            $args->setClassTable($table);
        }
    }
}
