<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;

/**
 * Maps the usage columns of {@see HttpLogEntry} (HL-1 §8.2) and their indexes. Registered only when
 * `nowo_http_log.outbound`, `nowo_http_log.mailer` or a named channel is enabled (Q-O1): hosts that enable nothing keep the 1.14
 * metadata, so `doctrine:schema:update --dump-sql` stays empty for them. Runs before {@see TablePrefixListener},
 * which then prefixes the index names below like the mapped ones.
 */
final readonly class UsageColumnsListener
{
    /**
     * Field => column definition. All nullable, no default, no backfill.
     *
     * @var array<string, array{column: string, type: string, length?: int}>
     */
    public const COLUMNS = [
        'channel'            => ['column' => 'channel', 'type' => Types::STRING, 'length' => 16],
        'provider'           => ['column' => 'provider', 'type' => Types::STRING, 'length' => 64],
        'credentialKind'     => ['column' => 'credential_kind', 'type' => Types::STRING, 'length' => 32],
        'credentialId'       => ['column' => 'credential_id', 'type' => Types::STRING, 'length' => 64],
        'tenantKey'          => ['column' => 'tenant_key', 'type' => Types::STRING, 'length' => 64],
        'projectKey'         => ['column' => 'project_key', 'type' => Types::STRING, 'length' => 64],
        'responseBytes'      => ['column' => 'response_bytes', 'type' => Types::BIGINT],
        'retryCount'         => ['column' => 'retry_count', 'type' => Types::SMALLINT],
        'errorKind'          => ['column' => 'error_kind', 'type' => Types::STRING, 'length' => 32],
        'messengerMessageId' => ['column' => 'messenger_message_id', 'type' => Types::STRING, 'length' => 64],
        'externalMessageId'  => ['column' => 'external_message_id', 'type' => Types::STRING, 'length' => 255],
    ];

    /**
     * Index name => columns. The longest name (`idx_http_log_provider_created`, 29 characters) bounds
     * `doctrine.table_prefix` while the usage columns are mapped ({@see TablePrefixListener::validate()}).
     *
     * @var array<string, array{columns: list<string>}>
     */
    public const INDEXES = [
        'idx_http_log_chan_created'     => ['columns' => ['channel', 'created_at']],
        'idx_http_log_tenant_created'   => ['columns' => ['tenant_key', 'created_at']],
        'idx_http_log_project_created'  => ['columns' => ['project_key', 'created_at']],
        'idx_http_log_provider_created' => ['columns' => ['provider', 'created_at']],
        'idx_http_log_cred_created'     => ['columns' => ['credential_kind', 'credential_id', 'created_at']],
    ];

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if ($metadata->getName() !== HttpLogEntry::class || $metadata->hasField('channel')) {
            return;
        }

        foreach (self::COLUMNS as $field => $definition) {
            $mapping = [
                'fieldName'  => $field,
                'columnName' => $definition['column'],
                'type'       => $definition['type'],
                'nullable'   => true,
            ];
            if (isset($definition['length'])) {
                $mapping['length'] = $definition['length'];
            }
            $metadata->mapField($mapping);
        }

        $metadata->setPrimaryTable(['indexes' => ($metadata->table['indexes'] ?? []) + self::INDEXES]);
    }
}
