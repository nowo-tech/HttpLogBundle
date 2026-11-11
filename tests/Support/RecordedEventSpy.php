<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Nowo\HttpLogBundle\Event\HttpLogEntryRecordedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/** Keeps the entry-recorded events and whether a transaction was open when each was dispatched. */
final class RecordedEventSpy implements EventDispatcherInterface
{
    /** @var list<HttpLogEntryRecordedEvent> */
    public array $dispatched = [];

    /** @var list<bool> */
    public array $inTransaction = [];

    public function __construct(private readonly UsageTestDatabase $db)
    {
    }

    public function dispatch(object $event): object
    {
        if ($event instanceof HttpLogEntryRecordedEvent) {
            $this->dispatched[]    = $event;
            $this->inTransaction[] = $this->db->connection()->isTransactionActive();
        }

        return $event;
    }
}
