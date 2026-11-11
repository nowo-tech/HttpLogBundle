<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function str_starts_with;
use function strtolower;
use function strval;

/**
 * HTTP edge of {@see HttpRequestContextResolverInterface}: builds the framework-free {@see HttpRequestContext} from a
 * Symfony request and token. Internal to the bundle (the resolution calls it); hosts receive the value object only.
 *
 * Credentials are left out: `Authorization`, `Cookie`, the `PHP_AUTH_*` pseudo headers, and every header listed in
 * `redaction.credential_headers` / `redaction.headers`. The query string is not part of the context at all.
 */
final readonly class HttpRequestContextFactory
{
    /** Headers that always carry a credential or a session. */
    public const ALWAYS_EXCLUDED_HEADERS = ['authorization', 'cookie', 'php-auth-user', 'php-auth-pw', 'php-auth-digest'];

    /** @var list<string> */
    private array $excludedHeaders;

    /** @param list<string> $excludedHeaders extra header names to leave out (case-insensitive) */
    public function __construct(array $excludedHeaders = [])
    {
        $this->excludedHeaders = array_values(array_unique(array_merge(self::ALWAYS_EXCLUDED_HEADERS, array_map(strtolower(...), $excludedHeaders))));
    }

    /**
     * @param TokenInterface|null $token only when a firewall with security enabled handled the request
     * @param string|null $userIdentifier the guarded user identifier (null when absent or when it leaks the credential)
     */
    public function create(Request $request, ?TokenInterface $token, ?string $userIdentifier): HttpRequestContext
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);
            if (in_array($name, $this->excludedHeaders, true)) {
                continue;
            }
            $list = [];
            foreach ($values as $value) {
                if (is_string($value)) {
                    $list[] = $value;
                }
            }
            $headers[$name] = $list;
        }

        $attributes = [];
        foreach ($request->attributes->all() as $name => $value) {
            if (!str_starts_with((string) $name, '_') && ($value === null || is_scalar($value))) {
                $attributes[(string) $name] = $value;
            }
        }

        $route = $request->attributes->get('_route');

        return new HttpRequestContext(
            method: $request->getMethod(),
            path: $request->getPathInfo(),
            routeName: is_string($route) ? $route : null,
            routeParams: $this->scalars($request->attributes->get('_route_params')),
            headers: $headers,
            attributes: $attributes,
            userIdentifier: $userIdentifier,
            roles: $token instanceof TokenInterface ? array_values(array_map(strval(...), $token->getRoleNames())) : [],
            tokenAttributes: $token instanceof TokenInterface ? $this->scalars($token->getAttributes()) : [],
        );
    }

    /** @return array<string, scalar|null> */
    private function scalars(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $scalars = [];
        foreach ($values as $name => $value) {
            if ($value === null || is_scalar($value)) {
                $scalars[(string) $name] = $value;
            }
        }

        return $scalars;
    }
}
