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
use Symfony\Component\Security\Core\Exception\LazyResponseException;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function array_diff;
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
 * characters), or when it leaks a credential the request carried:
 *
 * - the request's credential (the `Authorization` value: whole, after its scheme, the decoded Basic pair and password;
 *   and each value of a header in `redaction.credential_headers`): equal to it, containing it, or a prefix (6+
 *   characters) of it, at any length. A client cannot change these without losing its authentication (JAd-L1);
 * - every other redacted value (headers in `redaction.headers`, cookies while `Cookie` is listed, query parameters in
 *   `redaction.query_params`): only the credential id is checked against them, and only by exact equality (a resolver
 *   that wrongly returns a token taken from such a source). The client controls them all, so a looser rule, or one
 *   applied to the tenant, project or kind, would let it blank a value it knows (its tenant UUID sent as `?token=`).
 *
 * A resolver that throws is skipped with one warning (its class and the exception class).
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

    /**
     * @deprecated since 1.16.1, no longer used: values that are not the request's credential are matched by exact
     *             equality only, whatever their length (JAd-L1); the credential itself by contains / prefix at any length.
     */
    public const MIN_SECRET_LENGTH = 8;

    private LoggerInterface $logger;

    /**
     * @param iterable<HttpLogRequestContextResolverInterface> $resolvers tagged `nowo_http_log.request_context_resolver`
     * @param list<string> $redactedHeaders `redaction.headers`
     * @param list<string> $redactedQueryParams `redaction.query_params`
     * @param TokenStorageInterface|null $untrackedTokenStorage `security.untracked_token_storage`, to tell a lazy firewall that has
     *                                                          not authenticated yet
     * @param list<string> $credentialHeaders `redaction.credential_headers`: headers that carry the request's credential, like `Authorization`
     */
    public function __construct(
        private iterable $resolvers = [],
        private array $redactedHeaders = [],
        private array $redactedQueryParams = [],
        ?LoggerInterface $logger = null,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?Security $security = null,
        // any implementation (a host may decorate it); only Symfony's TokenStorage tells a pending lazy authentication
        private ?TokenStorageInterface $untrackedTokenStorage = null,
        private array $credentialHeaders = [],
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
     * Resolution just before the controller: only a non-empty result is kept on the request (JA-L1), so a request the
     * controller itself authenticates (`security: false` surfaces) is resolved again at terminate. A resolver may
     * therefore be called twice for one request when it answers null (or an empty context) the first time.
     */
    public function resolveEarly(Request $request, string $channel): HttpLogRequestContext
    {
        $cached = $request->attributes->get(self::ATTRIBUTE);
        if ($cached instanceof HttpLogRequestContext) {
            return $cached;
        }

        $context = $this->resolve($request, $channel, false);
        if ($context != new HttpLogRequestContext()) {
            $request->attributes->set(self::ATTRIBUTE, $context);
        }

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
            // no way to tell a pending lazy authentication (none, or a decorated storage): only safe without a token storage at all
            return !$this->tokenStorage instanceof TokenStorageInterface;
        }

        return $this->isInitialized($this->untrackedTokenStorage);
    }

    /**
     * The first non-null result of the resolvers, guarded; an empty context when none answers.
     *
     * @param bool $afterResponse at terminate: a lazy authenticator that fails there (LazyResponseException) gives a
     *                            null token instead of dropping the capture; never before the controller
     */
    public function resolve(Request $request, string $channel, bool $afterResponse = true): HttpLogRequestContext
    {
        if (!$this->hasResolvers()) {
            return new HttpLogRequestContext();
        }

        $token = $afterResponse ? $this->tokenAfterResponse($request) : $this->token($request);
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

    /** {@see token()} at terminate: the response is sent, a failing lazy authentication only means no token (JA-I3). */
    private function tokenAfterResponse(Request $request): ?TokenInterface
    {
        try {
            return $this->token($request);
        } catch (LazyResponseException) {
            return null;
        }
    }

    /**
     * The user identifier for a named-channel row, dropped (with a warning) when it equals, contains or starts a
     * credential the request carried: an authenticator whose user identifier is the API token must not store it.
     */
    public function userIdentifier(Request $request): ?string
    {
        $user = $this->tokenAfterResponse($request)?->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }

        $identifier = $user->getUserIdentifier();
        if ($this->leaks($identifier, ['credential' => $this->credentialSources($request)['credential'], 'redacted' => []])) {
            $this->logger->warning('HTTP log request context value dropped.', ['field' => 'user_identifier', 'reason' => 'credential']);

            return null;
        }

        return $identifier;
    }

    /**
     * Every credential-like value the request carried: {@see credentialSources()} plus the other redacted values.
     *
     * @return list<string>
     */
    public function secrets(Request $request): array
    {
        ['credential' => $credential, 'redacted' => $redacted] = $this->credentialSources($request);

        return array_values(array_unique([...$credential, ...$redacted]));
    }

    /**
     * The request's credential (Authorization-derived values and `redaction.credential_headers`), and the other redacted
     * values (headers, cookies, query parameters).
     *
     * @return array{credential: list<string>, redacted: list<string>}
     */
    public function credentialSources(Request $request): array
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

        foreach (array_map(strtolower(...), $this->credentialHeaders) as $name) {
            foreach ($request->headers->all($name) as $value) {
                $secrets[] = trim((string) $value);
            }
        }
        $credential = self::distinct($secrets);
        $secrets    = [];

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

        return ['credential' => $credential, 'redacted' => array_values(array_diff(self::distinct($secrets), $credential))];
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function distinct(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (string $s): bool => $s !== '')));
    }

    private function guard(Request $request, HttpLogRequestContext $context, string $resolver): HttpLogRequestContext
    {
        $secrets = $this->credentialSources($request);

        // tenant, project and kind name the account, not a credential: checked against the request's credential only
        $credentialOnly = ['credential' => $secrets['credential'], 'redacted' => []];

        return new HttpLogRequestContext(
            $this->clean($context->credentialKind, 32, $credentialOnly, 'credential_kind', $resolver),
            $this->clean($context->credentialId, 64, $secrets, 'credential_id', $resolver),
            $this->clean($context->tenantKey, 64, $credentialOnly, 'tenant_key', $resolver),
            $this->clean($context->projectKey, 64, $credentialOnly, 'project_key', $resolver),
        );
    }

    /** @param array{credential: list<string>, redacted: list<string>} $secrets */
    private function clean(?string $value, int $maxLength, array $secrets, string $field, string $resolver): ?string
    {
        if ($value === null) {
            return null;
        }

        $reason = UsageFieldGuard::token($value, $maxLength) === null ? 'invalid' : ($this->leaks($value, $secrets) ? 'credential' : null);
        if ($reason !== null) {
            $this->logger->warning('HTTP log request context value dropped.', ['resolver' => $resolver, 'field' => $field, 'reason' => $reason]);

            return null;
        }

        return $value;
    }

    /**
     * `$value` equals, contains or is a prefix (at least {@see MIN_PREFIX_LENGTH} long) of the request's credential, or
     * equals another redacted value.
     *
     * @param array{credential: list<string>, redacted: list<string>} $secrets
     */
    private function leaks(string $value, array $secrets): bool
    {
        foreach ($secrets['credential'] as $secret) {
            if (str_contains($value, $secret) || (strlen($value) >= self::MIN_PREFIX_LENGTH && str_starts_with($secret, $value))) {
                return true;
            }
        }

        return in_array($value, $secrets['redacted'], true);
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
