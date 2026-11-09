<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use Nowo\HttpLogBundle\Usage\UsageFieldGuard;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function array_walk_recursive;
use function base64_decode;
use function in_array;
use function is_array;
use function is_string;
use function str_contains;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Runs the {@see HttpLogRequestContextResolverInterface} hooks for a request of a named channel (HL-1 §6.3) and
 * guards what they return.
 *
 * Guard: a credential kind, credential id, tenant key or project key is dropped (stored NULL, one warning naming the
 * resolver class and the field, never the value) when it fails the opaque-key rule (`^[A-Za-z0-9._:@-]+$`, 32 / 64
 * characters), or when it equals or contains a credential the request carried, or is a prefix (6+ characters) of
 * one: the `Authorization` value (whole, after its scheme, and the decoded Basic pair and password), each value of a
 * header in `redaction.headers` (and each cookie while `Cookie` is listed), each value of a query parameter in
 * `redaction.query_params`. A resolver that throws is skipped with one warning (its class and the exception class).
 *
 * The token is passed only when a firewall with security enabled handled the request (the 1.14 rule of the user
 * identifier: a worker's token storage may still hold the previous request's token).
 */
final readonly class HttpLogRequestContextResolution
{
    /** Request attribute holding the resolved context (resolved at most once per request). */
    public const ATTRIBUTE = '_nowo_http_log_request_context';

    /** A returned value that is a prefix of a request credential this long or longer is dropped. */
    public const MIN_PREFIX_LENGTH = 6;

    private LoggerInterface $logger;

    /**
     * @param iterable<HttpLogRequestContextResolverInterface> $resolvers tagged `nowo_http_log.request_context_resolver`
     * @param list<string> $redactedHeaders `redaction.headers`
     * @param list<string> $redactedQueryParams `redaction.query_params`
     * @param TokenStorage|null $untrackedTokenStorage `security.untracked_token_storage`, to tell a lazy firewall that has
     *                                                 not authenticated yet
     */
    public function __construct(
        private iterable $resolvers = [],
        private array $redactedHeaders = [],
        private array $redactedQueryParams = [],
        ?LoggerInterface $logger = null,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?Security $security = null,
        private ?TokenStorage $untrackedTokenStorage = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** The guarded context of the request, resolved on first call and kept on the request. */
    public function forRequest(Request $request, string $channel): HttpLogRequestContext
    {
        $cached = $request->attributes->get(self::ATTRIBUTE);
        if ($cached instanceof HttpLogRequestContext) {
            return $cached;
        }

        $context = $this->resolve($request, $channel);
        $request->attributes->set(self::ATTRIBUTE, $context);

        return $context;
    }

    /**
     * True when the resolvers can run now without changing the request: there is a resolver, and reading the token
     * would not start a lazy firewall's authentication (which could turn an anonymous request into a 401).
     */
    public function canResolveEarly(): bool
    {
        if (!$this->hasResolvers()) {
            return false;
        }
        if (!$this->untrackedTokenStorage instanceof TokenStorage) {
            // no way to tell a pending lazy authentication: only safe without a token storage at all
            return !$this->tokenStorage instanceof TokenStorageInterface;
        }

        return $this->isInitialized($this->untrackedTokenStorage);
    }

    /** The first non-null result of the resolvers, guarded; an empty context when none answers. */
    public function resolve(Request $request, string $channel): HttpLogRequestContext
    {
        if (!$this->hasResolvers()) {
            return new HttpLogRequestContext();
        }

        $token = $this->token($request);
        foreach ($this->resolvers as $resolver) {
            try {
                $result = $resolver->resolve($request, $token, $channel);
            } catch (Throwable $exception) {
                $this->logger->warning('HTTP log request context resolver failed.', ['resolver' => $resolver::class, 'exception_class' => $exception::class]);

                continue;
            }
            if ($result instanceof HttpLogRequestContext) {
                return $this->guard($request, $result, $resolver::class);
            }
        }

        return new HttpLogRequestContext();
    }

    /** The security token, only when a firewall with security enabled handled the request. */
    public function token(Request $request): ?TokenInterface
    {
        if (!$this->tokenStorage instanceof TokenStorageInterface) {
            return null;
        }
        $secured = $this->security instanceof Security
            ? ($this->security->getFirewallConfig($request)?->isSecurityEnabled() ?? false)
            : $request->attributes->has('_firewall_context');

        return $secured ? $this->tokenStorage->getToken() : null;
    }

    /**
     * The user identifier for a named-channel row, dropped (with a warning) when it equals, contains or starts a
     * credential the request carried: an authenticator whose user identifier is the API token must not store it.
     */
    public function userIdentifier(Request $request): ?string
    {
        $user = $this->token($request)?->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }

        $identifier = $user->getUserIdentifier();
        foreach ($this->secrets($request) as $secret) {
            if ($this->leaks($identifier, $secret)) {
                $this->logger->warning('HTTP log request context value dropped.', ['field' => 'user_identifier', 'reason' => 'credential']);

                return null;
            }
        }

        return $identifier;
    }

    /**
     * Credentials the request carried, which no stored key may equal, contain or start.
     *
     * @return list<string>
     */
    public function secrets(Request $request): array
    {
        $secrets = [];
        $auth    = $request->headers->get('Authorization');
        if (is_string($auth) && trim($auth) !== '') {
            $auth      = trim($auth);
            $secrets[] = $auth;
            $space     = strpos($auth, ' ');
            if ($space !== false) {
                $value     = trim(substr($auth, $space + 1));
                $secrets[] = $value;
                $decoded   = strcasecmp(substr($auth, 0, $space), 'Basic') === 0 ? base64_decode($value, true) : false;
                if (is_string($decoded)) {
                    $secrets[] = $decoded;
                    $colon     = strpos($decoded, ':');
                    $secrets[] = $colon === false ? '' : substr($decoded, $colon + 1);
                }
            }
        }

        $headers = array_map(strtolower(...), $this->redactedHeaders);
        foreach ($headers as $name) {
            foreach ($request->headers->all($name) as $value) {
                $secrets[] = (string) $value;
            }
        }
        if (in_array('cookie', $headers, true)) {
            $cookies = $request->cookies->all();
            array_walk_recursive($cookies, static function (mixed $value) use (&$secrets): void {
                $secrets[] = is_string($value) ? $value : '';
            });
        }

        $params = array_map(strtolower(...), $this->redactedQueryParams);
        foreach ($request->query->all() as $name => $value) {
            if (!in_array(strtolower((string) $name), $params, true)) {
                continue;
            }
            $values = is_array($value) ? $value : [$value];
            array_walk_recursive($values, static function (mixed $v) use (&$secrets): void {
                $secrets[] = is_string($v) ? $v : '';
            });
        }

        return array_values(array_unique(array_filter($secrets, static fn (string $s): bool => $s !== '')));
    }

    private function guard(Request $request, HttpLogRequestContext $context, string $resolver): HttpLogRequestContext
    {
        $secrets = $this->secrets($request);

        return new HttpLogRequestContext(
            $this->clean($context->credentialKind, 32, $secrets, 'credential_kind', $resolver),
            $this->clean($context->credentialId, 64, $secrets, 'credential_id', $resolver),
            $this->clean($context->tenantKey, 64, $secrets, 'tenant_key', $resolver),
            $this->clean($context->projectKey, 64, $secrets, 'project_key', $resolver),
        );
    }

    /** @param list<string> $secrets */
    private function clean(?string $value, int $maxLength, array $secrets, string $field, string $resolver): ?string
    {
        if ($value === null) {
            return null;
        }

        $reason = UsageFieldGuard::token($value, $maxLength) === null ? 'invalid' : null;
        foreach ($secrets as $secret) {
            if ($reason === null && $this->leaks($value, $secret)) {
                $reason = 'credential';
            }
        }
        if ($reason !== null) {
            $this->logger->warning('HTTP log request context value dropped.', ['resolver' => $resolver, 'field' => $field, 'reason' => $reason]);

            return null;
        }

        return $value;
    }

    /** `$value` equals or contains `$secret`, or is a prefix of it at least {@see MIN_PREFIX_LENGTH} long. */
    private function leaks(string $value, string $secret): bool
    {
        return str_contains($value, $secret) || (strlen($value) >= self::MIN_PREFIX_LENGTH && str_starts_with($secret, $value));
    }

    private function hasResolvers(): bool
    {
        foreach ($this->resolvers as $resolver) {
            return true;
        }

        return false;
    }

    /** False while a lazy firewall's authenticator has not run yet (or when that cannot be told). */
    private function isInitialized(TokenStorage $storage): bool
    {
        $class = new ReflectionClass(TokenStorage::class);

        return $class->hasProperty('initializer') && $class->getProperty('initializer')->getValue($storage) === null;
    }
}
