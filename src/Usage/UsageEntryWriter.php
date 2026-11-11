<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\HttpLogBundle\Doctrine\PathPrefixColumnListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Event\HttpLogEntryRecordedEvent;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes usage rows with one DBAL `INSERT` on the connection of the entry's EntityManager (HL-1 §7.2). While the rollup
 * is enabled, a capture counted by it goes through {@see UsageRollupStore::record()}: the row (or, for a count-only
 * event, the ledger row) and the bucket upsert in one transaction, exactly once (HL-1 §9.3).
 *
 * Never `persist()` / `flush()`: outbound calls and mails happen inside host code (handlers, transactions), and a
 * flush of the shared manager would also write the host's pending entities. No entity is loaded or kept, so the
 * identity map never grows (worker safe). The table and column names come from the class metadata, so the configured
 * prefixes apply.
 */
final readonly class UsageEntryWriter
{
    public function __construct(
        private ManagerRegistry $registry,
        private ?UsageRollupStore $rollup = null,
        // REQ-ARCH-001 rule 4: HttpLogEntryRecordedEvent after a committed row
        private ?EventDispatcherInterface $events = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @throws LogicException when no EntityManager handles the entry or the usage columns are not mapped
     *
     * @return bool false when nothing was written: a row or event with the same id already exists (redelivered
     *              message), or a count-only event that is not counted (rollup disabled, older than its cutoff)
     */
    public function write(UsageCapture $capture): bool
    {
        if ($capture->rollup && $this->rollup instanceof UsageRollupStore) {
            // @igor-ignore - closure run once by record(), not kept
            $written = $this->rollup->record($capture, $capture->row ? function (Connection $connection) use ($capture): void {
                $this->insert($connection, $capture);
            } : null);
            if ($written && $capture->row) {
                $this->notifyRecorded($capture);
            }

            return $written;
        }
        if (!$capture->row) {
            return false;
        }

        try {
            $this->insert(null, $capture);
        } catch (UniqueConstraintViolationException) {
            return false;
        }
        $this->notifyRecorded($capture);

        return true;
    }

    /**
     * {@see HttpLogEntryRecordedEvent} for a usage row, only once it is committed: never while a transaction the host
     * left open is still active on the entry's connection. A failing listener is logged.
     */
    private function notifyRecorded(UsageCapture $capture): void
    {
        if (!$this->events instanceof EventDispatcherInterface) {
            return;
        }

        // the row is written: a failure to tell whether it is committed only skips the event
        try {
            $manager = $this->registry->getManagerForClass(HttpLogEntry::class);
            if (!$manager instanceof EntityManagerInterface || $manager->getConnection()->isTransactionActive()) {
                return;
            }
        } catch (Throwable $exception) {
            $this->logger?->warning('HTTP log entry-recorded event skipped: the transaction state could not be read.', ['exception_class' => $exception::class]);

            return;
        }

        try {
            $this->events->dispatch(new HttpLogEntryRecordedEvent(
                channel: $capture->channel,
                requestId: $capture->eventId,
                method: $capture->method,
                routeName: $capture->routeName,
                statusCode: $capture->statusCode,
                durationMs: $capture->durationMs,
                recordedAt: $capture->createdAt,
                tenantKey: $capture->tenantKey,
                projectKey: $capture->projectKey,
                credentialKind: $capture->credentialKind,
                credentialId: $capture->credentialId,
                provider: $capture->provider,
            ));
        } catch (Throwable $exception) {
            $this->logger?->warning('HTTP log entry-recorded listener failed.', ['exception_class' => $exception::class]);
        }
    }

    /**
     * @param Connection|null $connection null = the connection of the entry's EntityManager
     */
    private function insert(?Connection $connection, UsageCapture $capture): void
    {
        $manager = $this->registry->getManagerForClass(HttpLogEntry::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine EntityManager handles ' . HttpLogEntry::class . '.');
        }

        $metadata = $manager->getClassMetadata(HttpLogEntry::class);
        if (!$metadata->hasField('channel')) {
            throw new LogicException('The HttpLog usage columns are not mapped: enable nowo_http_log.outbound, nowo_http_log.mailer or a named channel.');
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
            'routeName'             => [$capture->routeName, ParameterType::STRING],
            'clientIp'              => [$capture->clientIp, ParameterType::STRING],
            'userIdentifier'        => [$capture->userIdentifier, ParameterType::STRING],
            'credentialKind'        => [$capture->credentialKind, ParameterType::STRING],
            'credentialId'          => [$capture->credentialId, ParameterType::STRING],
            'requestBodyTruncated'  => [false, Types::BOOLEAN],
            'responseBodyTruncated' => [false, Types::BOOLEAN],
            'responseBodyStored'    => [false, Types::BOOLEAN],
            'createdAt'             => [$capture->createdAt, Types::DATETIME_IMMUTABLE],
        ];

        if ($metadata->hasField(PathPrefixColumnListener::FIELD)) {
            // web_ui.filter_match: prefix (PERF-1147-02)
            $values[PathPrefixColumnListener::FIELD] = [HttpLogEntry::pathPrefixOf($capture->path), ParameterType::STRING];
        }

        $data  = [];
        $types = [];
        foreach ($values as $field => [$value, $type]) {
            $column         = $metadata->getColumnName($field);
            $data[$column]  = $value;
            $types[$column] = $value === null ? ParameterType::NULL : $type;
        }

        ($connection ?? $manager->getConnection())->insert($metadata->getTableName(), $data, $types);
    }
}
