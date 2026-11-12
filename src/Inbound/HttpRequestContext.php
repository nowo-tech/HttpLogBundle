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
 * security token. Its properties carry scalars only and it is never stored: the row still holds only what the
 * resolver returns (an {@see HttpLogRequestContext}), guarded as before.
 *
 * Headers are an allowlist (`redaction.context_headers`, default `accept`, `accept-language`, `content-type`,
 * `user-agent`, `x-request-id`; add your tenant header). `Authorization`, `Proxy-Authorization`, `Cookie`, `Referer`,
 * `Forwarded`, `X-Original-URL`, `X-Rewrite-URL`, the `redaction.credential_headers` / `redaction.headers` headers and
 * any header or attribute whose name contains token, secret, key, auth, session, signature, passw or cookie are never
 * in it, even when listed; nor is the query string. Resolve the credential from an **id** your authenticator stored as
 * a scalar token or request attribute (for example `credential_id`), or from the user identifier. Values that reach
 * the context must be ids, never secrets.
 *
 * Since 1.20, the host can name request attributes in `redaction.context_attributes` (default: none) to receive their
 * **raw** value, objects included, through {@see allowlistedAttribute()} (for example the credential object its
 * authenticator stamps on the request). The host's explicit allowlist wins over the secret-name and `_` prefix rules
 * of {@see $attributes}: naming an attribute there is the host's decision and responsibility. These values are never
 * stored in a property of this object (they live in an internal {@see \WeakMap} keyed by it), so no dump,
 * `var_export()`, `(array)` cast, `json_encode()` or `serialize()` of the context shows them; a `clone` or an
 * unserialized copy has none. The bundle never logs or persists them: they are only handed to the resolvers.
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
     * @param array<string, mixed> $allowlistedAttributes the raw values of the request attributes named in
     *                                                    `redaction.context_attributes` that the request has (kept
     *                                                    outside the object's properties)
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
        array $allowlistedAttributes = [],
    ) {
        if ($allowlistedAttributes !== []) {
            HttpRequestContextAttributes::put($this, $allowlistedAttributes);
        }
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

    /**
     * The raw value (objects included) of a request attribute the host named in `redaction.context_attributes`, or
     * null when the name is not allowlisted or the request has no such attribute. Since 1.20.
     */
    public function allowlistedAttribute(string $name): mixed
    {
        return HttpRequestContextAttributes::get($this, $name);
    }

    /** True when the host allowlisted this request attribute and the request has it (even with a null value). Since 1.20. */
    public function hasAllowlistedAttribute(string $name): bool
    {
        return HttpRequestContextAttributes::has($this, $name);
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
