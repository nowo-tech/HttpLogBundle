<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Context;

/**
 * One frame of {@see HttpLogContext}: the tenant (and project) an outbound call or a mail is made for, and the id of
 * the Messenger message being handled. Values are validated when a row is written (HL-1 §4.1), not here.
 */
final readonly class HttpLogFrame
{
    public function __construct(
        public ?string $tenantKey = null,
        public ?string $projectKey = null,
        public ?string $messengerMessageId = null,
    ) {
    }
}
