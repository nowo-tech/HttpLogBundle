<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Context;

/**
 * One frame of {@see HttpLogContext}: the tenant (and project) an outbound call or a mail is made for, and the id of
 * the Messenger message being handled, and (1.27) the correlation id. Values are validated when a row is written
 * (HL-1 §4.1), not here.
 */
final readonly class HttpLogFrame
{
    public function __construct(
        public ?string $tenantKey = null,
        public ?string $projectKey = null,
        public ?string $messengerMessageId = null,
        // 1.27: correlation id of the call / mail (filled by HttpLogContext::current() when the frame has none)
        public ?string $correlationId = null,
        // 1.27: true when $correlationId is the host provider's id (false: the request's fallback id, re-read at capture)
        public bool $providerCorrelation = false,
    ) {
    }
}
