<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Rollup;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;

/**
 * Names and DBAL schema of the two rollup tables (HL-1 §8.3): `<table_prefix><table_name_prefix>daily` (one bucket
 * per UTC day, channel, provider, credential and tenant) and `…event_seen` (the exactly-once ledger of count-only
 * events). `doctrine.table_prefix` also prefixes their index names, like the entry's.
 */
final readonly class RollupTables
{
    public const DAILY_SUFFIX = 'daily';

    public const EVENT_SEEN_SUFFIX = 'event_seen';

    /** Bucket key, in the order of the unique index. */
    public const KEY_COLUMNS = ['day', 'channel', 'provider', 'credential_kind', 'credential_id', 'tenant_key'];

    /** Histogram columns by inclusive upper bound in milliseconds; slower calls count in {@see OVERFLOW_COLUMN}. */
    public const HISTOGRAM = [
        50    => 'd_le_50',
        100   => 'd_le_100',
        250   => 'd_le_250',
        500   => 'd_le_500',
        1000  => 'd_le_1000',
        2500  => 'd_le_2500',
        5000  => 'd_le_5000',
        10000 => 'd_le_10000',
    ];

    public const OVERFLOW_COLUMN = 'd_gt_10000';

    /** Counters summed by the upsert. */
    public const COUNTER_COLUMNS = [
        'calls', 'errors', 'transport_errors', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx', 'retries',
        'bytes', 'rows_stored', 'duration_ms_sum', 'd_le_50', 'd_le_100', 'd_le_250', 'd_le_500', 'd_le_1000',
        'd_le_2500', 'd_le_5000', 'd_le_10000', 'd_gt_10000',
    ];

    /** Key columns other than `day` / `channel`, stored as '' instead of NULL so the unique key works everywhere. */
    public const KEY_LENGTHS = ['provider' => 64, 'credential_kind' => 32, 'credential_id' => 64, 'tenant_key' => 64];

    public const UNIQUE_KEY = 'uniq_http_log_daily_key';

    /** @var array<string, list<string>> */
    public const DAILY_INDEXES = [
        'idx_http_log_daily_tenant'   => ['tenant_key', 'day'],
        'idx_http_log_daily_provider' => ['provider', 'day'],
        'idx_http_log_daily_cred'     => ['credential_kind', 'credential_id', 'day'],
    ];

    public const SEEN_INDEX = 'idx_http_log_seen_day';

    public function __construct(
        public string $daily = TablePrefixListener::DEFAULT_TABLE_NAME_PREFIX . self::DAILY_SUFFIX,
        public string $eventSeen = TablePrefixListener::DEFAULT_TABLE_NAME_PREFIX . self::EVENT_SEEN_SUFFIX,
        public string $indexPrefix = '',
    ) {
    }

    /** Names for `doctrine.table_prefix` and `doctrine.table_name_prefix` (validated by {@see TablePrefixListener::validate()}). */
    public static function fromPrefixes(string $tablePrefix = '', string $tableNamePrefix = TablePrefixListener::DEFAULT_TABLE_NAME_PREFIX): self
    {
        return new self(
            $tablePrefix . $tableNamePrefix . self::DAILY_SUFFIX,
            $tablePrefix . $tableNamePrefix . self::EVENT_SEEN_SUFFIX,
            $tablePrefix,
        );
    }

    public function index(string $name): string
    {
        return $this->indexPrefix . $name;
    }

    /** MySQL / MariaDB collation of the key columns: case-sensitive, so `ORG-A` and `org-a` are two buckets. */
    public const MYSQL_KEY_COLLATION = 'utf8mb4_bin';

    /**
     * Adds both tables to `$schema` (no-op for a table already there).
     *
     * @param bool $mysql the platform is MySQL / MariaDB: the key columns get {@see MYSQL_KEY_COLLATION} (their
     *                    case-insensitive default collations would merge keys that differ in case)
     */
    public function addTo(Schema $schema, bool $mysql = false): void
    {
        $key = $mysql ? ['platformOptions' => ['collation' => self::MYSQL_KEY_COLLATION]] : [];
        if (!$schema->hasTable($this->daily)) {
            $daily = $schema->createTable($this->daily);
            $daily->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
            $daily->addColumn('day', Types::DATE_MUTABLE);
            $daily->addColumn('channel', Types::STRING, ['length' => 16] + $key);
            foreach (self::KEY_LENGTHS as $column => $length) {
                $daily->addColumn($column, Types::STRING, ['length' => $length, 'default' => ''] + $key);
            }
            foreach (self::COUNTER_COLUMNS as $column) {
                $daily->addColumn($column, Types::BIGINT, ['default' => 0]);
            }
            $daily->addColumn('duration_ms_max', Types::INTEGER, ['default' => 0]);
            $daily->addColumn('first_seen_at', Types::DATETIME_MUTABLE);
            $daily->addColumn('last_seen_at', Types::DATETIME_MUTABLE);
            $daily->setPrimaryKey(['id']);
            $daily->addUniqueIndex(self::KEY_COLUMNS, $this->index(self::UNIQUE_KEY));
            foreach (self::DAILY_INDEXES as $name => $columns) {
                $daily->addIndex($columns, $this->index($name));
            }
        }

        if (!$schema->hasTable($this->eventSeen)) {
            $seen = $schema->createTable($this->eventSeen);
            $seen->addColumn('event_id', Types::STRING, ['length' => 32, 'fixed' => true]);
            $seen->addColumn('day', Types::DATE_MUTABLE);
            $seen->setPrimaryKey(['event_id']);
            $seen->addIndex(['day'], $this->index(self::SEEN_INDEX));
        }
    }
}
