<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use InvalidArgumentException;

use function bin2hex;
use function is_string;
use function random_bytes;

/**
 * One allowlisted call of a usage channel (outbound HttpClient call, sent or failed mail), HL-1 §4.1.
 *
 * Every value goes through {@see UsageFieldGuard} in the constructor, so an instance (and the
 * `PersistHttpLogMessage` payload built from it) can only carry the allowlisted columns: no header, body, query
 * string, user info, address, subject or exception message has a field to travel in. `fromArray()` re-validates a
 * payload read back from a transport.
 */
final readonly class UsageCapture
{
    /** Payload key that marks a usage payload in `PersistHttpLogMessage` (web payloads never have it). */
    public const PAYLOAD_MARKER = 'channel';

    public string $eventId;
    public string $channel;
    public ?string $provider;
    public string $method;
    public ?string $scheme;
    public ?string $host;
    public string $path;
    public ?int $statusCode;
    public ?string $errorKind;
    public ?float $durationMs;
    public ?int $responseBytes;
    public ?int $retryCount;
    public ?string $contentType;
    public ?string $tenantKey;
    public ?string $projectKey;
    public ?string $messengerMessageId;
    public ?string $externalMessageId;

    /**
     * @param string $channel `outbound`, `mailer` (a channel name: lower-case, at most 16 characters)
     * @param string|null $eventId 32 hex characters; null (or an invalid id) generates one
     *
     * @throws InvalidArgumentException when the channel name is invalid
     */
    public function __construct(
        string $channel,
        public DateTimeImmutable $createdAt,
        string $method,
        string $path = '/',
        ?string $provider = null,
        ?string $scheme = null,
        ?string $host = null,
        ?int $statusCode = null,
        ?string $errorKind = null,
        ?float $durationMs = null,
        ?int $responseBytes = null,
        ?int $retryCount = null,
        ?string $contentType = null,
        ?string $tenantKey = null,
        ?string $projectKey = null,
        ?string $messengerMessageId = null,
        ?string $externalMessageId = null,
        ?string $eventId = null,
    ) {
        $validChannel = UsageFieldGuard::channel($channel);
        if ($validChannel === null || $validChannel === HttpLogChannel::WEB) {
            throw new InvalidArgumentException('Invalid usage channel name.');
        }

        $this->channel            = $validChannel;
        $this->eventId            = UsageFieldGuard::eventId($eventId) ?? self::newEventId();
        $this->method             = UsageFieldGuard::method($method);
        $this->path               = UsageFieldGuard::path($path);
        $this->provider           = UsageFieldGuard::token($provider);
        $this->scheme             = UsageFieldGuard::scheme($scheme);
        $this->host               = UsageFieldGuard::host($host);
        $this->statusCode         = UsageFieldGuard::statusCode($statusCode);
        $this->errorKind          = UsageFieldGuard::errorKind($errorKind);
        $this->durationMs         = UsageFieldGuard::duration($durationMs);
        $this->responseBytes      = UsageFieldGuard::nonNegativeInt($responseBytes);
        $this->retryCount         = UsageFieldGuard::nonNegativeInt($retryCount, 32767);
        $this->contentType        = UsageFieldGuard::contentType($contentType);
        $this->tenantKey          = UsageFieldGuard::token($tenantKey);
        $this->projectKey         = UsageFieldGuard::token($projectKey);
        $this->messengerMessageId = UsageFieldGuard::messageId($messengerMessageId);
        $this->externalMessageId  = UsageFieldGuard::externalMessageId($externalMessageId);
    }

    /** Server-generated event id (32 hex): the `request_id` of the row and its dedupe key. */
    public static function newEventId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /** True when the call failed: an error kind, or a status >= 400. */
    public function isError(): bool
    {
        return $this->errorKind !== null || ($this->statusCode !== null && $this->statusCode >= 400);
    }

    /**
     * Messenger payload: the allowlisted fields only.
     *
     * @return array<string, float|int|string|null>
     */
    public function toArray(): array
    {
        return [
            self::PAYLOAD_MARKER => $this->channel,
            'eventId'            => $this->eventId,
            'provider'           => $this->provider,
            'method'             => $this->method,
            'scheme'             => $this->scheme,
            'host'               => $this->host,
            'path'               => $this->path,
            'statusCode'         => $this->statusCode,
            'errorKind'          => $this->errorKind,
            'durationMs'         => $this->durationMs,
            'responseBytes'      => $this->responseBytes,
            'retryCount'         => $this->retryCount,
            'contentType'        => $this->contentType,
            'tenantKey'          => $this->tenantKey,
            'projectKey'         => $this->projectKey,
            'messengerMessageId' => $this->messengerMessageId,
            'externalMessageId'  => $this->externalMessageId,
            'createdAt'          => $this->createdAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Rebuilds a capture from a payload; every field is validated again (a payload is data from a transport).
     *
     * @param array<string, mixed> $data
     *
     * @throws InvalidArgumentException when the channel or the creation time is missing or invalid
     */
    public static function fromArray(array $data): self
    {
        $channel   = $data[self::PAYLOAD_MARKER] ?? null;
        $createdAt = $data['createdAt'] ?? null;
        if (!is_string($channel) || !is_string($createdAt)) {
            throw new InvalidArgumentException('A usage payload needs a channel and a creation time.');
        }

        try {
            $created = new DateTimeImmutable($createdAt);
        } catch (Exception $exception) {
            throw new InvalidArgumentException('Invalid usage payload creation time.', 0, $exception);
        }

        return new self(
            channel: $channel,
            createdAt: $created,
            method: self::string($data['method'] ?? null) ?? UsageFieldGuard::OTHER_METHOD,
            path: self::string($data['path'] ?? null) ?? UsageFieldGuard::COLLAPSED_PATH,
            provider: self::string($data['provider'] ?? null),
            scheme: self::string($data['scheme'] ?? null),
            host: self::string($data['host'] ?? null),
            statusCode: UsageFieldGuard::statusCode($data['statusCode'] ?? null),
            errorKind: self::string($data['errorKind'] ?? null),
            durationMs: UsageFieldGuard::duration($data['durationMs'] ?? null),
            responseBytes: UsageFieldGuard::nonNegativeInt($data['responseBytes'] ?? null),
            retryCount: UsageFieldGuard::nonNegativeInt($data['retryCount'] ?? null, 32767),
            contentType: self::string($data['contentType'] ?? null),
            tenantKey: self::string($data['tenantKey'] ?? null),
            projectKey: self::string($data['projectKey'] ?? null),
            messengerMessageId: self::string($data['messengerMessageId'] ?? null),
            externalMessageId: self::string($data['externalMessageId'] ?? null),
            eventId: self::string($data['eventId'] ?? null),
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
