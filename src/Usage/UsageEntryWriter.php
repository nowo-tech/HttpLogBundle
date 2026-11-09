<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;

/**
 * Writes usage rows with one DBAL `INSERT` on the connection of the entry's EntityManager (HL-1 §7.2).
 *
 * Never `persist()` / `flush()`: outbound calls and mails happen inside host code (handlers, transactions), and a
 * flush of the shared manager would also write the host's pending entities. No entity is loaded or kept, so the
 * identity map never grows (worker safe). The table and column names come from the class metadata, so the configured
 * prefixes apply.
 */
final readonly class UsageEntryWriter
{
    public function __construct(private ManagerRegistry $registry)
    {
    }

    /**
     * @throws LogicException when no EntityManager handles the entry or the usage columns are not mapped
     *
     * @return bool false when a row with the same event id already exists (redelivered message): nothing written
     */
    public function write(UsageCapture $capture): bool
    {
        $manager = $this->registry->getManagerForClass(HttpLogEntry::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine EntityManager handles ' . HttpLogEntry::class . '.');
        }

        $metadata = $manager->getClassMetadata(HttpLogEntry::class);
        if (!$metadata->hasField('channel')) {
            throw new LogicException('The HttpLog usage columns are not mapped: enable nowo_http_log.outbound or nowo_http_log.mailer.');
        }

        $values = [
            'requestId'             => [$capture->eventId, ParameterType::STRING],
            'channel'               => [$capture->channel, ParameterType::STRING],
            'provider'              => [$capture->provider, ParameterType::STRING],
            'method'                => [$capture->method, ParameterType::STRING],
            'scheme'                => [$capture->scheme, ParameterType::STRING],
            'host'                  => [$capture->host, ParameterType::STRING],
            'path'                  => [$capture->path, ParameterType::STRING],
            'statusCode'            => [$capture->statusCode, ParameterType::INTEGER],
            'errorKind'             => [$capture->errorKind, ParameterType::STRING],
            'durationMs'            => [$capture->durationMs, Types::FLOAT],
            'responseBytes'         => [$capture->responseBytes, Types::BIGINT],
            'retryCount'            => [$capture->retryCount, ParameterType::INTEGER],
            'contentType'           => [$capture->contentType, ParameterType::STRING],
            'tenantKey'             => [$capture->tenantKey, ParameterType::STRING],
            'projectKey'            => [$capture->projectKey, ParameterType::STRING],
            'messengerMessageId'    => [$capture->messengerMessageId, ParameterType::STRING],
            'externalMessageId'     => [$capture->externalMessageId, ParameterType::STRING],
            'requestBodyTruncated'  => [false, Types::BOOLEAN],
            'responseBodyTruncated' => [false, Types::BOOLEAN],
            'responseBodyStored'    => [false, Types::BOOLEAN],
            'createdAt'             => [$capture->createdAt, Types::DATETIME_IMMUTABLE],
        ];

        $data  = [];
        $types = [];
        foreach ($values as $field => [$value, $type]) {
            $column         = $metadata->getColumnName($field);
            $data[$column]  = $value;
            $types[$column] = $value === null ? ParameterType::NULL : $type;
        }

        try {
            $manager->getConnection()->insert($metadata->getTableName(), $data, $types);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
