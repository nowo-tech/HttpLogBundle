<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Where a flushed usage capture goes (HL-1 §7.2): one `PersistHttpLogMessage` with the allowlisted payload when
 * `nowo_http_log.async` is true, else a direct DBAL insert. No `DispatchAfterCurrentBusStamp`: it would discard the
 * log of a failing handler.
 */
final readonly class UsageCaptureSink
{
    public function __construct(
        private UsageEntryWriter $writer,
        private bool $async,
        private ?MessageBusInterface $messageBus = null,
    ) {
    }

    public function write(UsageCapture $capture): void
    {
        if ($this->async && $this->messageBus instanceof MessageBusInterface) {
            $this->messageBus->dispatch(new PersistHttpLogMessage($capture->toArray()));

            return;
        }

        $this->writer->write($capture);
    }
}
