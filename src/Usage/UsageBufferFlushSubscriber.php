<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Flushes {@see HttpLogBuffer} at every boundary (HL-1 §7.1 rule 4): after the response is sent
 * (`kernel.terminate`), after a command (`console.terminate`) and after each message a worker handled or failed
 * (the handler's transaction is over by then).
 */
final readonly class UsageBufferFlushSubscriber implements EventSubscriberInterface
{
    /** `ConsoleEvents::TERMINATE` (symfony/console is optional). */
    private const CONSOLE_TERMINATE = 'console.terminate';

    public function __construct(private HttpLogBuffer $buffer)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::TERMINATE          => ['flush', -2048],
            self::CONSOLE_TERMINATE          => ['flush', -2048],
            WorkerMessageHandledEvent::class => ['flush', -2048],
            WorkerMessageFailedEvent::class  => ['flush', -2048],
        ];
    }

    public function flush(): void
    {
        $this->buffer->flush();
    }
}
