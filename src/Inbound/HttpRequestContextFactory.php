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
use function preg_match;
use function str_starts_with;
use function strtolower;
use function strval;

/**
 * HTTP edge of {@see HttpRequestContextResolverInterface}: builds the framework-free {@see HttpRequestContext} from a
 * Symfony request and token. Internal to the bundle (the resolution calls it); hosts receive the value object only.
 *
 * Headers are an **allowlist**: only the names in `redaction.context_headers` (default {@see DEFAULT_CONTEXT_HEADERS})
 * are copied. Even a listed name is dropped when it is one of {@see ALWAYS_EXCLUDED_HEADERS}, is listed in
 * `redaction.credential_headers` / `redaction.headers`, or looks like a secret ({@see SECRET_NAME_PATTERN}: token,
 * secret, key, auth, session, signature, password, cookie). Request and token attributes keep only scalar values whose
 * name does not look like a secret. The query string is never part of the context.
 *
 * Values that reach the context must be ids (tenant UUID, credential row id), never secrets: the filters above are a
 * safety net, not a licence to keep a token in an attribute.
 */
final readonly class HttpRequestContextFactory
{
    /** Safe default of `redaction.context_headers`. */
    public const DEFAULT_CONTEXT_HEADERS = ['accept', 'accept-language', 'content-type', 'user-agent', 'x-request-id'];

    /** Headers never copied, even when the host lists them. */
    public const ALWAYS_EXCLUDED_HEADERS = [
        'authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'referer', 'forwarded', 'x-original-url',
        'x-rewrite-url', 'php-auth-user', 'php-auth-pw', 'php-auth-digest',
    ];

    /** A header or attribute name matching this is never copied (case-insensitive). */
    public const SECRET_NAME_PATTERN = '/token|secret|key|auth|session|signature|passw|cookie/i';

    /** @var list<string> */
    private array $allowedHeaders;

    /**
     * @param list<string> $excludedHeaders header names to leave out even when allowed (the credential and redacted headers)
     * @param list<string> $allowedHeaders `redaction.context_headers` (case-insensitive)
     */
    public function __construct(array $excludedHeaders = [], array $allowedHeaders = self::DEFAULT_CONTEXT_HEADERS)
    {
        $excluded = array_merge(self::ALWAYS_EXCLUDED_HEADERS, array_map(strtolower(...), $excludedHeaders));
        $allowed  = [];
        foreach (array_unique(array_map(strtolower(...), $allowedHeaders)) as $name) {
            if (!in_array($name, $excluded, true) && !self::isSecretName($name)) {
                $allowed[] = $name;
            }
        }
        $this->allowedHeaders = $allowed;
    }

    /** True when a header or attribute name looks like it carries a secret (never copied). */
    public static function isSecretName(string $name): bool
    {
        return preg_match(self::SECRET_NAME_PATTERN, $name) === 1;
    }

    /** @return list<string> the header names actually copied (the allowlist minus the exclusions) */
    public function allowedHeaders(): array
    {
        return $this->allowedHeaders;
    }

    /**
     * @param TokenInterface|null $token only when a firewall with security enabled handled the request
     * @param string|null $userIdentifier the guarded user identifier (null when absent or when it leaks the credential)
     */
    public function create(Request $request, ?TokenInterface $token, ?string $userIdentifier): HttpRequestContext
    {
        $headers = [];
        foreach ($this->allowedHeaders as $name) {
            $list = [];
            foreach ($request->headers->all($name) as $value) {
                if (is_string($value)) {
                    $list[] = $value;
                }
            }
            if ($list !== []) {
                $headers[$name] = $list;
            }
        }

        $attributes = [];
        foreach ($this->scalars($request->attributes->all()) as $name => $value) {
            if (!str_starts_with($name, '_')) {
                $attributes[$name] = $value;
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

    /**
     * The scalar (or null) values whose name does not look like a secret.
     *
     * @return array<string, scalar|null>
     */
    private function scalars(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $scalars = [];
        foreach ($values as $name => $value) {
            $name = (string) $name;
            if (($value === null || is_scalar($value)) && !self::isSecretName($name)) {
                $scalars[$name] = $value;
            }
        }

        return $scalars;
    }
}
