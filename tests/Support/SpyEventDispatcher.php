<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataErasedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Keeps the erased events and whether a transaction was open on the connection when each was dispatched.
 */
final class SpyEventDispatcher implements EventDispatcherInterface
{
    /** @var list<HttpLogPersonalDataErasedEvent> */
    public array $dispatched = [];

    /** @var list<bool> */
    public array $inTransaction = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function dispatch(object $event): object
    {
        if ($event instanceof HttpLogPersonalDataErasedEvent) {
            $this->dispatched[]    = $event;
            $this->inTransaction[] = $this->connection->isTransactionActive();
        }

        return $event;
    }
}
