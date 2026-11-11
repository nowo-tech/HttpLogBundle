<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Context;

/**
 * Tenant / project context that outbound calls and mails read (HL-1 §6.4), as a port (REQ-ARCH-001 rule 1). The
 * service `Nowo\HttpLogBundle\Context\HttpLogContextInterface` is a public alias of {@see HttpLogContext}; type-hint
 * this interface in host code.
 *
 * ```php
 * $context->run($tenantId, fn () => $sync->pull($board), $projectId);
 * ```
 */
interface HttpLogContextInterface
{
    /**
     * Runs `$fn` with a frame for the tenant (and project); the current Messenger message id is kept. The frame is
     * always removed when `$fn` returns or throws.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function run(string $tenantKey, callable $fn, ?string $projectKey = null): mixed;

    /**
     * Runs `$fn` with `$frame` on top of the stack; the frame is always removed when `$fn` returns or throws.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function runFrame(HttpLogFrame $frame, callable $fn): mixed;

    /** The innermost frame, or an empty one outside any `run()`. */
    public function current(): HttpLogFrame;
}
