<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Message;

use DateTimeImmutable;

final readonly class PurgeHttpLogMessage
{
    /** @param array<string, mixed>|null $criteria */
    public function __construct(
        public ?DateTimeImmutable $before,
        public ?array $criteria = null,
    ) {
    }
}
