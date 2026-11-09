<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Added once on dispatch by {@see HttpLogContextMiddleware}: a stable message id (32 hex, the same on every retry and
 * transport) and the tenant / project in scope when the message was dispatched (`messenger.propagate_tenant`).
 */
final readonly class HttpLogContextStamp implements StampInterface
{
    public function __construct(
        public string $messageId,
        public ?string $tenantKey = null,
        public ?string $projectKey = null,
    ) {
    }
}
