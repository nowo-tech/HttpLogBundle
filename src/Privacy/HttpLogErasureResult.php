<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

/**
 * Outcome of {@see HttpLogPersonalDataErasureInterface::erase()}: rows deleted per kind.
 */
final readonly class HttpLogErasureResult
{
    /**
     * @param int $entries HTTP log entries deleted
     */
    public function __construct(
        public int $entries = 0,
    ) {
    }

    /** @return array{entries: int} */
    public function toArray(): array
    {
        return ['entries' => $this->entries];
    }

    public function total(): int
    {
        return $this->entries;
    }
}
