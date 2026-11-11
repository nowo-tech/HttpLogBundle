<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Host hook that names the credential and tenant of a request recorded by a named channel (HL-1 §6.3). Services
 * implementing it are tagged `nowo_http_log.request_context_resolver` (autoconfigured; `priority` orders them, the
 * first non-null result wins).
 *
 * Called at most once per request of a named channel: just before the controller runs (so outbound calls and mails it
 * makes inherit the tenant), or at `kernel.terminate` when no controller ran. A resolver that throws is skipped with
 * one warning; the request is never affected.
 *
 * @deprecated since 1.19, implement the framework-free {@see HttpRequestContextResolverInterface} instead
 *             (REQ-ARCH-001 rule 3: no `Request` or `TokenInterface` in public ports); removed in 2.0. Resolvers that
 *             implement only this interface keep working unchanged until then (same tag, same priority order, same
 *             guard); the container build reports a deprecation for each of them.
 */
interface HttpLogRequestContextResolverInterface
{
    public const TAG = 'nowo_http_log.request_context_resolver';

    /**
     * @param TokenInterface|null $token the security token, only when a firewall with security enabled handled the
     *                                   request (null otherwise, also in a worker that reuses the token storage)
     * @param string $channel the named channel that matched (`api`, `webhooks`…)
     */
    public function resolve(Request $request, ?TokenInterface $token, string $channel): ?HttpLogRequestContext;
}
