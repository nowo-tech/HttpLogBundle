<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;

/**
 * Maps `path_prefix` (the first {@see HttpLogEntry::PATH_PREFIX_LENGTH} characters of the path) and its index
 * (PERF-1147-02). Registered only with `nowo_http_log.web_ui.filter_match: prefix`: the admin `q` / `path` /
 * `routeName` filters then match a prefix through indexes instead of `LIKE '%…%'` over the whole table. Hosts that
 * keep `contains` keep their schema. Runs before {@see TablePrefixListener}, which then prefixes the index name.
 */
final readonly class PathPrefixColumnListener
{
    public const FIELD = 'pathPrefix';

    public const COLUMN = 'path_prefix';

    /** 24 characters, within TablePrefixListener's longest fixed index name. */
    public const INDEX = 'idx_http_log_path_prefix';

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if ($metadata->getName() !== HttpLogEntry::class || $metadata->hasField(self::FIELD)) {
            return;
        }

        $metadata->mapField([
            'fieldName'  => self::FIELD,
            'columnName' => self::COLUMN,
            'type'       => Types::STRING,
            'length'     => HttpLogEntry::PATH_PREFIX_LENGTH,
            'nullable'   => true,
        ]);
        $metadata->setPrimaryTable(['indexes' => ($metadata->table['indexes'] ?? []) + [self::INDEX => ['columns' => [self::COLUMN, 'created_at']]]]);
    }
}
