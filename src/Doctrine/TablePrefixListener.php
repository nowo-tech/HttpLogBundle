<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Doctrine;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use InvalidArgumentException;

use function is_string;
use function max;
use function preg_match;
use function sprintf;
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

    /** The table is `<table_prefix><table_name_prefix>entry` (published as `nowo_http_log.doctrine.entry_table`). */
    public const ENTRY_TABLE_SUFFIX = 'entry';

    /** Longest identifier PostgreSQL keeps (MySQL allows 64): every generated name must fit. */
    public const MAX_IDENTIFIER_LENGTH = 63;

    /** Allowed prefix: lowercase letter first, then lowercase letters, digits and underscores (no trailing newline). */
    public const PREFIX_PATTERN = '/^[a-z][a-z0-9_]*\z/';

    /** Longest mapped name once `DEFAULT_TABLE_NAME_PREFIX` is removed: `entry_id_seq`, the PostgreSQL identity sequence of the table, without `nowo_http_log_`. */
    private const LONGEST_NAME_REST = 12;

    /** Longest named index without `DEFAULT_TABLE_NAME_PREFIX` (only `table_prefix` lengthens it): idx_http_log_status_code. */
    private const LONGEST_FIXED_NAME = 24;

    /** Longest named index while the usage columns are mapped ({@see UsageColumnsListener}): idx_http_log_provider_created. */
    public const LONGEST_USAGE_NAME = 29;

    private const ENTITY_NAMESPACE = 'Nowo\\HttpLogBundle\\Entity\\';

    /**
     * Builds the listener from the configured prefixes.
     *
     * @param string $tablePrefix Optional prefix prepended to every name (empty for none)
     * @param string $tableNamePrefix Replaces `nowo_http_log_` in every table, index and unique-constraint name
     * @param bool $usageColumns The usage columns and their longer index names are mapped (outbound / mailer enabled)
     */
    public function __construct(
        private string $tablePrefix,
        private string $tableNamePrefix = self::DEFAULT_TABLE_NAME_PREFIX,
        bool $usageColumns = false,
    ) {
        self::validate($this->tablePrefix, $this->tableNamePrefix, $usageColumns);
    }

    /**
     * Rejects prefixes that are not lowercase identifiers or that make a generated name longer than 63 characters.
     * Run by the extension for literal values and by the constructor for resolved `%env()%` values.
     *
     * @param string $tablePrefix Optional prefix prepended to every name (empty for none)
     * @param string $tableNamePrefix Replacement of `DEFAULT_TABLE_NAME_PREFIX`
     * @param bool $usageColumns The usage indexes (up to {@see LONGEST_USAGE_NAME} characters) are mapped as well
     *
     * @throws InvalidArgumentException when a prefix is malformed or a name would be too long
     */
    public static function validate(string $tablePrefix, string $tableNamePrefix, bool $usageColumns = false): void
    {
        if (preg_match(self::PREFIX_PATTERN, $tableNamePrefix) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid doctrine.table_name_prefix "%s": use a lowercase letter, then lowercase letters, digits and underscores.', $tableNamePrefix));
        }
        if ($tablePrefix !== '' && preg_match(self::PREFIX_PATTERN, $tablePrefix) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid doctrine.table_prefix "%s": use a lowercase letter, then lowercase letters, digits and underscores (or empty).', $tablePrefix));
        }
        $longest = max(strlen($tablePrefix) + strlen($tableNamePrefix) + self::LONGEST_NAME_REST, strlen($tablePrefix) + ($usageColumns ? self::LONGEST_USAGE_NAME : self::LONGEST_FIXED_NAME));
        if ($longest > self::MAX_IDENTIFIER_LENGTH) {
            throw new InvalidArgumentException(sprintf('doctrine.table_prefix "%s" + doctrine.table_name_prefix "%s" make a table, index or sequence name of %d characters; the limit is %d.', $tablePrefix, $tableNamePrefix, $longest, self::MAX_IDENTIFIER_LENGTH));
        }
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
