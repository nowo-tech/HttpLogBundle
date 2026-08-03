<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Message;

final readonly class PersistHttpLogMessage
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public array $payload,
    ) {
    }
}
