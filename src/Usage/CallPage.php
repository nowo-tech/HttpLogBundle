<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

/**
 * One page of {@see HttpLogUsageReaderInterface::calls()}, newest first; `nextCursor` is null on the last page.
 */
final readonly class CallPage
{
    /** @param list<UsageCall> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
