<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use DateTimeImmutable;

/**
 * One per-call row of a usage channel (HL-1 §4.1 allowlist): never headers, query strings or bodies.
 */
final readonly class UsageCall
{
    public function __construct(
        public int $id,
        public ?string $eventId,
        public string $channel,
        public ?string $provider,
        public string $method,
        public ?string $scheme,
        public ?string $host,
        public string $path,
        public ?string $routeName,
        public ?int $statusCode,
        public ?string $errorKind,
        public ?float $durationMs,
        public ?int $responseBytes,
        public ?int $retryCount,
        public ?string $contentType,
        public ?string $clientIp,
        public ?string $userIdentifier,
        public ?string $credentialKind,
        public ?string $credentialId,
        public ?string $tenantKey,
        public ?string $projectKey,
        public ?string $messengerMessageId,
        public ?string $externalMessageId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
