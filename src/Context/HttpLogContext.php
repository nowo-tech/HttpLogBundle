<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Context;

use Symfony\Contracts\Service\ResetInterface;

use function array_slice;
use function count;

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
final class HttpLogContext implements ResetInterface
{
    /** @var list<HttpLogFrame> */
    private array $frames = [];

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

    /** The innermost frame, or an empty one outside any `run()`. */
    public function current(): HttpLogFrame
    {
        return $this->frames[count($this->frames) - 1] ?? new HttpLogFrame();
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
