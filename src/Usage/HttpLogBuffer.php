<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use Nowo\HttpLogBundle\Privacy\HttpLogErasedSubjects;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_splice;
use function count;

/**
 * Queue of usage captures, written at a request / command / message boundary (HL-1 §7.1 rule 4) or when it holds
 * {@see FLUSH_AT} captures. So a host handler that rolls back its transaction does not roll back the log of its
 * failed third-party call, and a request adds no query before its response is sent.
 *
 * While the buffer writes, outbound capture is suspended ({@see isSuspended()}): a Messenger transport or a database
 * driver that itself uses a decorated HttpClient cannot loop. Stateful by design: `kernel.reset` flushes, then
 * clears it (worker safe).
 */
final class HttpLogBuffer implements ResetInterface
{
    /** Captures kept at most; more are dropped and counted in one warning. */
    public const CAPACITY = 500;

    /** Captures that trigger a flush on add. */
    public const FLUSH_AT = 100;

    /** @var list<UsageCapture> */
    private array $pending = [];

    private int $dropped = 0;

    private int $suspended = 0;

    private readonly LoggerInterface $logger;

    /**
     * @param HttpLogErasedSubjects|null $erased captures of a value erased in the same boundary are dropped (LY-L2)
     */
    public function __construct(
        private readonly UsageCaptureSink $sink,
        ?LoggerInterface $logger = null,
        private readonly ?HttpLogErasedSubjects $erased = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function add(UsageCapture $capture): void
    {
        if (count($this->pending) >= self::CAPACITY) {
            ++$this->dropped;

            return;
        }

        $this->pending[] = $capture;
        if (count($this->pending) >= self::FLUSH_AT && $this->suspended === 0) {
            $this->flush();
        }
    }

    /**
     * Writes every queued capture. A capture that fails is dropped with one warning (exception class only); logging
     * never throws into the caller.
     */
    public function flush(): void
    {
        if ($this->suspended > 0) {
            return;
        }

        if ($this->dropped > 0) {
            $this->logger->warning('HTTP log buffer overflow: usage captures dropped.', ['dropped' => $this->dropped, 'capacity' => self::CAPACITY]);
            $this->dropped = 0;
        }

        while ($this->pending !== []) {
            $batch = array_splice($this->pending, 0);
            foreach ($batch as $capture) {
                if ($this->erased?->matches($capture->userIdentifier, $capture->clientIp) === true) {
                    continue; // personal data erased earlier in this request / command / message
                }
                $this->suspended(function () use ($capture): void {
                    try {
                        $this->sink->write($capture);
                    } catch (Throwable $exception) {
                        $this->logger->warning('HTTP log usage capture dropped.', ['channel' => $capture->channel, 'exception_class' => $exception::class]);
                    }
                });
            }
        }
    }

    /**
     * Runs `$fn` with outbound capture suspended (the bundle's own dispatch and writes).
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function suspended(callable $fn): mixed
    {
        ++$this->suspended;

        try {
            return $fn();
        } finally {
            --$this->suspended;
        }
    }

    public function isSuspended(): bool
    {
        return $this->suspended > 0;
    }

    public function count(): int
    {
        return count($this->pending);
    }

    /** Flushes, then forgets everything (`kernel.reset`, between worker requests and messages). */
    public function reset(): void
    {
        $this->suspended = 0;
        $this->flush();
        $this->pending = [];
        $this->dropped = 0;
    }
}
