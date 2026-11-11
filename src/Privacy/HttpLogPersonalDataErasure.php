<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Default {@see HttpLogPersonalDataErasureInterface}: deletes through {@see HttpLogPersonalDataEraserInterface}, in the
 * caller's transaction or in its own, and dispatches the event after its own commit only.
 */
final readonly class HttpLogPersonalDataErasure implements HttpLogPersonalDataErasureInterface
{
    public function __construct(
        private HttpLogPersonalDataEraserInterface $eraser,
        private HttpLogEntryRepository $repository,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    public function erase(HttpLogPersonalDataSubject $subject): HttpLogErasureResult
    {
        $connection = $this->repository->connection();
        if ($connection->isTransactionActive()) {
            return new HttpLogErasureResult($this->delete($subject));
        }

        $result = new HttpLogErasureResult($connection->transactional(fn (): int => $this->delete($subject)));
        $this->events?->dispatch(new HttpLogPersonalDataErasedEvent($subject->type, $subject->value, $result->toArray()));

        return $result;
    }

    private function delete(HttpLogPersonalDataSubject $subject): int
    {
        return $subject->isClientIp()
            ? $this->eraser->deleteByClientIp($subject->value)
            : $this->eraser->deleteByUserIdentifier($subject->value);
    }
}
