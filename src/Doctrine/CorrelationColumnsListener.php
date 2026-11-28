<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\CorrelationContract\CorrelationId;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;

/**
 * Maps `correlation_id` and `upstream_id` (1.27, Beacon spec 235) and their indexes. Registered only with
 * `nowo_http_log.correlation.columns: true`: a host that does not enable it keeps its schema (no migration), like the
 * `error_code` column. Every reader and writer of the columns checks `hasField(self::FIELD)`. Runs before
 * {@see TablePrefixListener}, which then prefixes the index names.
 */
final readonly class CorrelationColumnsListener
{
    public const FIELD = 'correlationId';

    public const COLUMN = 'correlation_id';

    public const UPSTREAM_FIELD = 'upstreamId';

    public const UPSTREAM_COLUMN = 'upstream_id';

    /** At most 24 characters: TablePrefixListener's longest fixed index name. */
    public const INDEX = 'idx_http_log_corr_id';

    public const UPSTREAM_INDEX = 'idx_http_log_upstream';

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if ($metadata->getName() !== HttpLogEntry::class || $metadata->hasField(self::FIELD)) {
            return;
        }

        foreach ([self::FIELD => self::COLUMN, self::UPSTREAM_FIELD => self::UPSTREAM_COLUMN] as $field => $column) {
            $metadata->mapField([
                'fieldName'  => $field,
                'columnName' => $column,
                'type'       => Types::STRING,
                'length'     => CorrelationId::MAX_LENGTH,
                'nullable'   => true,
            ]);
        }
        $metadata->setPrimaryTable(['indexes' => ($metadata->table['indexes'] ?? []) + [
            self::INDEX          => ['columns' => [self::COLUMN, 'created_at']],
            self::UPSTREAM_INDEX => ['columns' => [self::UPSTREAM_COLUMN, 'created_at']],
        ]]);
    }
}
