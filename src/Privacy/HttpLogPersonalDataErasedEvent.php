<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

/**
 * Dispatched after {@see HttpLogPersonalDataErasureInterface::erase()} committed the transaction it opened. Not
 * dispatched when the caller's transaction was already open (the returned result is then the outcome), and never
 * before a commit.
 */
final readonly class HttpLogPersonalDataErasedEvent
{
    /**
     * @param HttpLogPersonalDataSubject::TYPE_* $subjectType
     * @param non-empty-string $subjectValue
     * @param array<string, int> $counts rows deleted per kind ({@see HttpLogErasureResult::toArray()})
     */
    public function __construct(
        public string $subjectType,
        public string $subjectValue,
        public array $counts,
    ) {
    }

    public function total(): int
    {
        $total = 0;
        foreach ($this->counts as $count) {
            $total += $count;
        }

        return $total;
    }
}
