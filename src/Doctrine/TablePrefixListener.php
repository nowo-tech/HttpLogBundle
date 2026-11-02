<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

use function is_string;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr_replace;

/**
 * Applies the configurable naming of the HttpLog tables and their named indexes (REQ-ORM-002).
 *
 * `nowo_http_log.doctrine.table_name_prefix` replaces the `nowo_http_log_` part of the table name (`http_log_` gives
 * http_log_entry; the `idx_http_log_*` index names do not contain it and stay as they are). The optional
 * `nowo_http_log.doctrine.table_prefix` is then prepended to the table and index names (`app_` gives
 * app_nowo_http_log_entry and app_idx_http_log_created_at). Only entities of this bundle are touched. Registered only
 * when either option differs from its default, so default names are left exactly as mapped.
 */
final readonly class TablePrefixListener
{
    /** The name prefix every table (and every derived index / unique-constraint name) of the bundle is mapped with. */
    public const DEFAULT_TABLE_NAME_PREFIX = 'nowo_http_log_';

    private const ENTITY_NAMESPACE = 'Nowo\\HttpLogBundle\\Entity\\';

    /**
     * Builds the listener from the configured prefixes.
     *
     * @param string $tablePrefix Optional prefix prepended to every name (empty for none)
     * @param string $tableNamePrefix Replaces `nowo_http_log_` in every table, index and unique-constraint name
     */
    public function __construct(
        private string $tablePrefix,
        private string $tableNamePrefix = self::DEFAULT_TABLE_NAME_PREFIX,
    ) {
    }

    /**
     * Rewrites the table, index and unique-constraint names of a bundle entity while its metadata loads.
     *
     * @param LoadClassMetadataEventArgs $event Doctrine metadata event
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if (($this->tablePrefix === '' && $this->tableNamePrefix === self::DEFAULT_TABLE_NAME_PREFIX)
            || !str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
            return;
        }

        $table = ['name' => $this->rename($metadata->getTableName())];
        foreach (['indexes', 'uniqueConstraints'] as $key) {
            $definitions = $metadata->table[$key] ?? [];
            if ($definitions === []) {
                continue;
            }

            $renamed = [];
            foreach ($definitions as $name => $definition) {
                $renamed[is_string($name) ? $this->rename($name) : $name] = $definition;
            }
            $table[$key] = $renamed;
        }

        $metadata->setPrimaryTable($table);
    }

    /**
     * Swaps the first `nowo_http_log_` of a mapped name for the configured name prefix, then prepends the optional
     * prefix.
     *
     * @param string $name Table, index or unique-constraint name as mapped on the entity
     */
    private function rename(string $name): string
    {
        $position = strpos($name, self::DEFAULT_TABLE_NAME_PREFIX);
        if ($position !== false) {
            $name = substr_replace($name, $this->tableNamePrefix, $position, strlen(self::DEFAULT_TABLE_NAME_PREFIX));
        }

        return $this->tablePrefix . $name;
    }
}
