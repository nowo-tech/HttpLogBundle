<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;

/**
 * Maps `error_code` (the provider's machine code of a failed outbound call) and its index. Registered only while at least
 * one `nowo_http_log.outbound.providers.<key>.error_code.paths` is configured: a host that does not use the option keeps
 * its schema (no migration), like the `path_prefix` column. Every reader and writer of the column checks
 * `hasField(self::FIELD)`. Runs before {@see TablePrefixListener}, which then prefixes the index name.
 */
final readonly class ErrorCodeColumnListener
{
    public const FIELD = 'errorCode';

    public const COLUMN = 'error_code';

    /** 28 characters, within TablePrefixListener's longest fixed index name. */
    public const INDEX = 'idx_http_log_errcode_created';

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
            'length'     => 128,
            'nullable'   => true,
        ]);
        $metadata->setPrimaryTable(['indexes' => ($metadata->table['indexes'] ?? []) + [self::INDEX => ['columns' => [self::COLUMN, 'created_at']]]]);
    }
}
