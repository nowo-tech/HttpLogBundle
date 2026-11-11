<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Kernel;

use Nowo\HttpLogBundle\Event\HttpLogEntryRecordedEvent;

/** A DDD host's listener of the entry-recorded event: keeps only the ids it is given. */
final class DddHostEntryListener
{
    /** @var list<array{channel: string, request_id: string|null, tenant: string|null, credential: string|null, status: int|null}> */
    public array $recorded = [];

    public function __invoke(HttpLogEntryRecordedEvent $event): void
    {
        $this->recorded[] = ['channel' => $event->channel, 'request_id' => $event->requestId, 'tenant' => $event->tenantKey, 'credential' => $event->credentialId, 'status' => $event->statusCode];
    }

    public function clear(): void
    {
        $this->recorded = [];
    }
}
