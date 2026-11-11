<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use function array_key_exists;
use function strtolower;

/**
 * Framework-free view of one request of a named channel, given to a {@see HttpRequestContextResolverInterface}
 * (REQ-ARCH-001 rule 3: no Symfony `Request` or `TokenInterface` in a public port).
 *
 * Built by the bundle at the HTTP edge from the request and, only when a firewall with security enabled handled it, the
 * security token. It carries scalars only and is never stored: the row still holds only what the resolver returns
 * (an {@see HttpLogRequestContext}), guarded as before.
 *
 * Headers are an allowlist (`redaction.context_headers`, default `accept`, `accept-language`, `content-type`,
 * `user-agent`, `x-request-id`; add your tenant header). `Authorization`, `Proxy-Authorization`, `Cookie`, `Referer`,
 * `Forwarded`, `X-Original-URL`, `X-Rewrite-URL`, the `redaction.credential_headers` / `redaction.headers` headers and
 * any header or attribute whose name contains token, secret, key, auth, session, signature, passw or cookie are never
 * in it, even when listed; nor is the query string. Resolve the credential from an **id** your authenticator stored as
 * a scalar token or request attribute (for example `credential_id`), or from the user identifier. Values that reach
 * the context must be ids, never secrets.
 */
final readonly class HttpRequestContext
{
    /**
     * @param string $method the HTTP method (upper case)
     * @param string $path the path info (no query string)
     * @param string|null $routeName the matched route (`_route`), null when unrouted
     * @param array<string, scalar|null> $routeParams the matched route parameters with a scalar value whose name does not look like a secret
     * @param array<string, list<string>> $headers the allowlisted request headers (lower-case names) that were sent
     * @param array<string, scalar|null> $attributes the request attributes with a scalar value whose name neither starts with `_` nor looks like a secret
     * @param string|null $userIdentifier the authenticated user's identifier; null without a secured firewall or user, and
     *                                    when it equals, contains or starts the request's credential
     * @param list<string> $roles the role names of the security token (empty without one)
     * @param array<string, scalar|null> $tokenAttributes the security token attributes with a scalar value whose name does not look like a secret
     */
    public function __construct(
        public string $method,
        public string $path,
        public ?string $routeName = null,
        public array $routeParams = [],
        public array $headers = [],
        public array $attributes = [],
        public ?string $userIdentifier = null,
        public array $roles = [],
        public array $tokenAttributes = [],
    ) {
    }

    /** The first value of a header (case-insensitive name), or null when it is absent or not allowlisted. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** A scalar request attribute, or null. */
    public function attribute(string $name): bool|float|int|string|null
    {
        return $this->attributes[$name] ?? null;
    }

    /** A scalar route parameter, or null. */
    public function routeParam(string $name): bool|float|int|string|null
    {
        return $this->routeParams[$name] ?? null;
    }

    /** A scalar security token attribute, or null. */
    public function tokenAttribute(string $name): bool|float|int|string|null
    {
        return $this->tokenAttributes[$name] ?? null;
    }

    public function hasTokenAttribute(string $name): bool
    {
        return array_key_exists($name, $this->tokenAttributes);
    }

    /** True when a secured firewall authenticated a user for this request. */
    public function isAuthenticated(): bool
    {
        return $this->userIdentifier !== null;
    }
}
