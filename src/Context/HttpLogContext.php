<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Context;

use Nowo\HttpLogBundle\Correlation\CorrelationIds;
use Symfony\Contracts\Service\ResetInterface;

use function array_slice;
use function count;
use function max;

/**
 * Stack of {@see HttpLogFrame} that outbound calls and mails read their tenant, project and Messenger message id
 * from (HL-1 §6.4). Frames are pushed for the duration of a callable and always popped in `finally`, so an exception
 * or a nested `run()` never leaves a frame behind; `kernel.reset` empties the stack between worker requests and
 * messages.
 *
 * ```php
 * $context->run($organization->getUuid(), fn () => $sync->pull($board), $project->getUuid());
 * ```
 */
final class HttpLogContext implements HttpLogContextInterface, ResetInterface
{
    /** @var list<HttpLogFrame> */
    private array $frames = [];

    /**
     * @param CorrelationIds|null $correlation 1.27: fills the correlation id of {@see current()} at call time (never stored)
     */
    public function __construct(
        private readonly ?CorrelationIds $correlation = null,
    ) {
    }

    /**
     * Runs `$fn` with a frame for the tenant (and project); the current Messenger message id is kept.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function run(string $tenantKey, callable $fn, ?string $projectKey = null): mixed
    {
        return $this->runFrame(new HttpLogFrame($tenantKey, $projectKey, $this->current()->messengerMessageId), $fn);
    }

    /**
     * Runs `$fn` with `$frame` on top of the stack.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function runFrame(HttpLogFrame $frame, callable $fn): mixed
    {
        $depth          = count($this->frames);
        $this->frames[] = $frame;

        try {
            return $fn();
        } finally {
            $this->frames = array_slice($this->frames, 0, $depth);
        }
    }

    /**
     * Pushes a frame that stays until {@see restore()} (or `kernel.reset`): the request frame of a named channel,
     * popped at `kernel.terminate`. Prefer {@see run()}.
     *
     * @return int the depth to restore
     */
    public function push(HttpLogFrame $frame): int
    {
        $depth          = count($this->frames);
        $this->frames[] = $frame;

        return $depth;
    }

    /** Drops every frame above `$depth`. */
    public function restore(int $depth): void
    {
        $this->frames = array_slice($this->frames, 0, max(0, $depth));
    }

    /**
     * The innermost frame, or an empty one outside any `run()`. 1.27: a frame without a correlation id gets the current
     * one (host provider, else the main request's id), read now: outbound calls and mails keep the frame of their start.
     */
    public function current(): HttpLogFrame
    {
        $frame = $this->frames[count($this->frames) - 1] ?? new HttpLogFrame();
        if ($frame->correlationId !== null || !$this->correlation instanceof CorrelationIds) {
            return $frame;
        }

        $id = $this->correlation->current();

        return $id === null ? $frame : new HttpLogFrame($frame->tenantKey, $frame->projectKey, $frame->messengerMessageId, $id);
    }

    public function depth(): int
    {
        return count($this->frames);
    }

    public function reset(): void
    {
        $this->frames = [];
    }
}
