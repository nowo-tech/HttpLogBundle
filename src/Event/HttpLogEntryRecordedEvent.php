<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Event;

use DateTimeImmutable;

/**
 * Dispatched after an HTTP log row was written and committed (REQ-ARCH-001 rules 4 and 7): a web-channel entry, or a
 * usage row of a named inbound channel, `outbound` or `mailer`.
 *
 * Carries ids and scalars only, never the path, query string, headers, bodies, client IP or user identifier: listen
 * to it to react to traffic (counters, alerts) and read the row back through the bundle's ports when you need more.
 *
 * Not dispatched when the insert ran inside a transaction the host left open (the row is not committed yet and may
 * still roll back), when nothing was written (a redelivered message, a count-only rollup event), or when the write
 * failed. A listener that throws is logged and ignored: the row stays written.
 */
final readonly class HttpLogEntryRecordedEvent
{
    /** Channel name of the web (request / response) entries. */
    public const CHANNEL_WEB = 'web';

    /**
     * @param string $channel `web`, `outbound`, `mailer` or a named inbound channel
     * @param string|null $requestId the row's `request_id`: the web request id (null when none was set) or the usage event id
     * @param string|null $routeName the matched route (web and named inbound channels), null otherwise
     * @param int|null $statusCode the response status; null for a failed outbound call or a mail
     * @param string|null $tenantKey opaque tenant key (usage rows only)
     * @param string|null $projectKey opaque project key (usage rows only)
     * @param string|null $credentialKind opaque credential kind (named inbound channels only)
     * @param string|null $credentialId credential database id (named inbound channels only), never the credential
     * @param string|null $provider outbound / mailer provider key
     */
    public function __construct(
        public string $channel,
        public ?string $requestId,
        public string $method,
        public ?string $routeName,
        public ?int $statusCode,
        public ?float $durationMs,
        public DateTimeImmutable $recordedAt,
        public ?string $tenantKey = null,
        public ?string $projectKey = null,
        public ?string $credentialKind = null,
        public ?string $credentialId = null,
        public ?string $provider = null,
    ) {
    }

    public function isWeb(): bool
    {
        return $this->channel === self::CHANNEL_WEB;
    }
}
