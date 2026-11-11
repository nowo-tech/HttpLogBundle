<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

/**
 * Host hook that names the credential and tenant of a request recorded by a named channel (HL-1 §6.3), without any
 * Symfony type (REQ-ARCH-001 rule 3). It replaces {@see HttpLogRequestContextResolverInterface}, deprecated since
 * 1.19 and removed in 2.0.
 *
 * Services implementing it are tagged `nowo_http_log.request_context_resolver`
 * ({@see HttpLogRequestContextResolution::RESOLVER_TAG}; autoconfigured, `priority` orders them together with the
 * deprecated resolvers, the first non-null result wins). A class that implements both interfaces is called through
 * this one. (The interface declares no constant, so a class can implement both during the migration.)
 *
 * Called at most once per request of a named channel: just before the controller runs, or at `kernel.terminate` when
 * no controller ran. A resolver that throws is skipped with one warning; the request is never affected. What it
 * returns goes through the same guard as before (opaque-key rule, never the request's credential).
 */
interface HttpRequestContextResolverInterface
{
    /**
     * @param HttpRequestContext $request scalars of the request; never the credential headers, cookies or query string
     * @param string $channel the named channel that matched (`api`, `webhooks`…)
     */
    public function resolveContext(HttpRequestContext $request, string $channel): ?HttpLogRequestContext;
}
