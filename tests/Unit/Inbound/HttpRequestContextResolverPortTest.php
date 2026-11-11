<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Inbound;

use LogicException;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolverInterface;
use Nowo\HttpLogBundle\Inbound\HttpRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpRequestContextFactory;
use Nowo\HttpLogBundle\Inbound\HttpRequestContextResolverInterface;
use Nowo\HttpLogBundle\Tests\Support\CollectingLogger;
use Nowo\HttpLogBundle\Tests\Support\RecordingContextResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_column;
use function array_keys;
use function base64_encode;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * REQ-ARCH-001 rule 3: the framework-free resolver port (`HttpRequestContextResolverInterface`, given an
 * `HttpRequestContext`) runs in the same chain, order and guard as the deprecated `Request` / `TokenInterface` port,
 * and gets no credential.
 */
final class HttpRequestContextResolverPortTest extends TestCase
{
    /**
     * A host allowlist that also names every header the bundle must refuse anyway (MR-H-L1): only `accept` and the
     * tenant header survive.
     */
    private const CONTEXT_HEADERS = [
        'Accept', 'X-Test-Org', 'Authorization', 'Proxy-Authorization', 'Cookie', 'Referer', 'Forwarded', 'X-Original-URL',
        'X-Rewrite-URL', 'X-Auth-Token', 'X-Forwarded-Access-Token', 'X-Access-Token', 'X-Session-Id', 'X-Hub-Signature',
        'X-Client-Secret', 'X-Api-Key', 'X-Beacon-Token',
    ];

    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new CollectingLogger();
    }

    /** @param list<HttpLogRequestContextResolverInterface|HttpRequestContextResolverInterface> $resolvers */
    /** @param list<string> $contextHeaders */
    private function resolution(array $resolvers, ?TokenStorage $storage = null, array $contextHeaders = self::CONTEXT_HEADERS): HttpLogRequestContextResolution
    {
        return new HttpLogRequestContextResolution($resolvers, ['Authorization', 'Cookie', 'X-Api-Key'], ['token', 'api_key'], $this->logger, $storage, null, null, ['X-Beacon-Token'], $contextHeaders);
    }

    private function legacy(?HttpLogRequestContext $context): HttpLogRequestContextResolverInterface
    {
        return new class($context) implements HttpLogRequestContextResolverInterface {
            public function __construct(private readonly ?HttpLogRequestContext $context)
            {
            }

            public function resolve(Request $request, ?TokenInterface $token, string $channel): ?HttpLogRequestContext
            {
                return $this->context;
            }
        };
    }

    private function port(?HttpLogRequestContext $context): RecordingContextResolver
    {
        return new RecordingContextResolver($context);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function guardedValues(): iterable
    {
        yield 'a clean context' => ['credentialId', 'pt-7'];
        yield 'the bearer value' => ['credentialId', 'SEED14placeholder'];
        yield 'contains the bearer' => ['credentialId', 'id-SEED14placeholder'];
        yield 'a prefix of the bearer' => ['credentialId', 'SEED14'];
        yield 'the bearer as the tenant' => ['tenantKey', 'SEED14placeholder'];
        yield 'an api key header' => ['credentialId', 'SEED30placeholder'];
        yield 'a credential header' => ['tenantKey', 'SEED36beacon'];
        yield 'a cookie' => ['credentialId', 'SEED31cookie'];
        yield 'a redacted query param' => ['credentialId', 'SEED15placeholder'];
        yield 'not an opaque key' => ['credentialId', 'has space'];
        yield 'too long' => ['credentialKind', 'a-credential-kind-of-forty-characters-xx'];
    }

    /** Old port vs new port: the same answer is guarded to the same stored context, with the same warnings. */
    #[Test]
    #[DataProvider('guardedValues')]
    public function theGuardIsTheSameForBothPorts(string $field, string $value): void
    {
        $context = new HttpLogRequestContext(...[$field => $value, 'credentialKind' => $field === 'credentialKind' ? $value : 'personal_token']);
        $request = static fn (): Request => Request::create('/api/x?TOKEN=SEED15placeholder', 'GET', [], ['sid' => 'SEED31cookie'], [], ['HTTP_AUTHORIZATION' => 'Bearer SEED14placeholder', 'HTTP_X_API_KEY' => 'SEED30placeholder', 'HTTP_X_BEACON_TOKEN' => 'SEED36beacon']);

        $old          = $this->resolution([$this->legacy($context)])->resolve($request(), 'api');
        $oldWarnings  = $this->logger->warnings();
        $this->logger = new CollectingLogger();
        $new          = $this->resolution([$this->port($context)])->resolve($request(), 'api');

        self::assertEquals($old, $new);
        self::assertSame(array_column(array_column($oldWarnings, 'context'), 'field'), array_column(array_column($this->logger->warnings(), 'context'), 'field'));
        self::assertStringNotContainsString($value === 'pt-7' ? 'SEED' : $value, json_encode($this->logger->records, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function theContextNeverCarriesACredential(): void
    {
        $resolver = $this->port(null);
        $request  = Request::create('/api/tasks/42?token=SEED15placeholder', 'POST', [], ['sid' => 'SEED31cookie'], [], [
            'HTTP_AUTHORIZATION'            => 'Basic ' . base64_encode('client:SEED33secret'),
            'HTTP_X_API_KEY'                => 'SEED30placeholder',
            'HTTP_X_BEACON_TOKEN'           => 'SEED36beacon',
            'HTTP_X_TEST_ORG'               => 'org-a',
            'HTTP_ACCEPT'                   => 'application/json',
            'HTTP_X_AUTH_TOKEN'             => 'SEED40placeholder',
            'HTTP_X_FORWARDED_ACCESS_TOKEN' => 'SEED41placeholder',
            'HTTP_X_ACCESS_TOKEN'           => 'SEED42placeholder',
            'HTTP_PROXY_AUTHORIZATION'      => 'Basic SEED43placeholder',
            'HTTP_REFERER'                  => 'https://app.example.test/share?token=SEED44placeholder',
            'HTTP_FORWARDED'                => 'for=192.0.2.60;proto=https',
            'HTTP_X_ORIGINAL_URL'           => '/api/tasks/42?token=SEED45placeholder',
            'HTTP_X_REWRITE_URL'            => '/api/tasks/42?token=SEED46placeholder',
            'HTTP_X_SESSION_ID'             => 'SEED47placeholder',
            'HTTP_X_HUB_SIGNATURE'          => 'sha256=SEED48placeholder',
            'HTTP_X_CLIENT_SECRET'          => 'SEED49placeholder',
            'HTTP_USER_AGENT'               => 'not-allowlisted-here',
        ]);
        $request->attributes->add([
            '_route'        => 'api_task',
            '_route_params' => ['id' => '42', 'nested' => ['x'], 'token' => 'SEED52placeholder'],
            'credential_id' => 'pt-7',
            'api_token'     => 'SEED50placeholder',
            'session_id'    => 'SEED51placeholder',
            'object'        => new HttpLogRequestContext(),
            '_internal'     => 'hidden',
        ]);

        $this->resolution([$resolver])->resolve($request, 'api');

        $context = $resolver->contexts[0] ?? null;
        self::assertInstanceOf(HttpRequestContext::class, $context);
        self::assertSame(['api'], $resolver->channels);
        self::assertSame('POST', $context->method);
        self::assertSame('/api/tasks/42', $context->path);
        self::assertSame('api_task', $context->routeName);
        self::assertSame(['id' => '42'], $context->routeParams);
        self::assertSame('42', $context->routeParam('id'));
        self::assertSame(['credential_id' => 'pt-7'], $context->attributes, 'secret-like attribute names are dropped');
        self::assertSame('pt-7', $context->attribute('credential_id'));
        self::assertSame(['accept' => ['application/json'], 'x-test-org' => ['org-a']], $context->headers, 'allowlist minus the always-excluded and secret-like names');
        self::assertSame('org-a', $context->header('X-Test-Org'));
        self::assertNull($context->header('user-agent'), 'not in this host\'s allowlist');
        foreach (['authorization', 'proxy-authorization', 'cookie', 'referer', 'forwarded', 'x-original-url', 'x-rewrite-url', 'x-auth-token', 'x-forwarded-access-token', 'x-access-token', 'x-session-id', 'x-hub-signature', 'x-client-secret', 'x-api-key', 'x-beacon-token', 'php-auth-user', 'php-auth-pw'] as $name) {
            self::assertNull($context->header($name), $name);
        }
        self::assertNull($context->userIdentifier);
        self::assertFalse($context->isAuthenticated());
        self::assertSame([], $context->roles);
        self::assertSame([], $context->tokenAttributes);
        self::assertStringNotContainsString('SEED', json_encode($context, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function theUserRolesAndScalarTokenAttributesComeOnlyFromASecuredFirewall(): void
    {
        $token = new UsernamePasswordToken(new InMemoryUser('ana@example.test', null, ['ROLE_API']), 'api', ['ROLE_API']);
        $token->setAttribute('credential_id', 'pt-9');
        $token->setAttribute('api_token_id', 'SEED53placeholder');
        $token->setAttribute('refresh_secret', 'SEED54placeholder');
        $token->setAttribute('tenant', new HttpLogRequestContext());
        $storage = new TokenStorage();
        $storage->setToken($token);

        $resolver = $this->port(new HttpLogRequestContext('personal_token', 'pt-9', 'org-a'));
        $request  = Request::create('/api/x');
        self::assertEquals(new HttpLogRequestContext(), $this->resolution([$this->port(null)], $storage)->resolve($request, 'api'));

        $request->attributes->set('_firewall_context', 'security.firewall.map.context.api');
        self::assertEquals(new HttpLogRequestContext('personal_token', 'pt-9', 'org-a'), $this->resolution([$resolver], $storage)->resolve($request, 'api'));
        $context = $resolver->contexts[0] ?? null;
        self::assertInstanceOf(HttpRequestContext::class, $context);
        self::assertSame('ana@example.test', $context->userIdentifier);
        self::assertTrue($context->isAuthenticated());
        self::assertSame(['ROLE_API'], $context->roles);
        self::assertSame(['credential_id' => 'pt-9'], $context->tokenAttributes, 'objects and secret-like names are dropped');
        self::assertSame('pt-9', $context->tokenAttribute('credential_id'));
        self::assertTrue($context->hasTokenAttribute('credential_id'));
        self::assertFalse($context->hasTokenAttribute('api_token_id'));
        self::assertFalse($context->hasTokenAttribute('tenant'));

        // a user identifier that is the request's credential is not given to the port either
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('SEED14placeholder', null), 'api'));
        $bearer = Request::create('/api/x', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer SEED14placeholder']);
        $bearer->attributes->set('_firewall_context', 'api');
        $leaky = $this->port(null);
        $this->resolution([$leaky], $storage)->resolve($bearer, 'api');
        self::assertNull(($leaky->contexts[0] ?? null)?->userIdentifier);
    }

    #[Test]
    public function theDefaultAllowlistGivesOnlyTheSafeHeaders(): void
    {
        $resolver = $this->port(null);
        $request  = Request::create('/api/x', 'GET', [], [], [], [
            'HTTP_ACCEPT'          => 'application/json',
            'HTTP_ACCEPT_LANGUAGE' => 'es',
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_USER_AGENT'      => 'agent/1.0',
            'HTTP_X_REQUEST_ID'    => 'req-1',
            'HTTP_X_TEST_ORG'      => 'org-a',
            'HTTP_X_AUTH_TOKEN'    => 'SEED40placeholder',
            'HTTP_REFERER'         => 'https://app.example.test/?token=SEED44placeholder',
        ]);

        $this->resolution([$resolver], null, HttpRequestContextFactory::DEFAULT_CONTEXT_HEADERS)->resolve($request, 'api');

        $context = $resolver->contexts[0] ?? null;
        self::assertInstanceOf(HttpRequestContext::class, $context);
        self::assertSame(['accept', 'accept-language', 'content-type', 'user-agent', 'x-request-id'], array_keys($context->headers));
        self::assertNull($context->header('x-test-org'), 'a tenant header must be allowlisted by the host');
        self::assertStringNotContainsString('SEED', json_encode($context, JSON_THROW_ON_ERROR));
        self::assertSame(['accept', 'x-test-org'], (new HttpRequestContextFactory(['X-Api-Key'], self::CONTEXT_HEADERS))->allowedHeaders());
        self::assertTrue(HttpRequestContextFactory::isSecretName('X-Forwarded-Access-Token'));
        self::assertFalse(HttpRequestContextFactory::isSecretName('credential_id'));
    }

    #[Test]
    public function bothPortsShareOnePriorityChainAndTheContextIsBuiltOnce(): void
    {
        $throwing = new class implements HttpRequestContextResolverInterface {
            public function resolveContext(HttpRequestContext $request, string $channel): ?HttpLogRequestContext
            {
                throw new LogicException('SEED35 in a message');
            }
        };
        $first  = $this->port(null);
        $second = $this->port(null);
        $legacy = $this->legacy(new HttpLogRequestContext('personal_token', 'pt-1', 'org-a'));
        $later  = $this->port(new HttpLogRequestContext('other'));

        $resolved = $this->resolution([$throwing, $first, $second, $legacy, $later])->resolve(Request::create('/api/x'), 'api');

        self::assertEquals(new HttpLogRequestContext('personal_token', 'pt-1', 'org-a'), $resolved, 'the legacy resolver answers first');
        self::assertSame([], $later->contexts, 'never asked after a non-null answer');
        self::assertSame($first->contexts[0] ?? null, $second->contexts[0] ?? null, 'one context per resolution');
        self::assertSame([LogicException::class], array_column(array_column($this->logger->warnings(), 'context'), 'exception_class'));
        self::assertStringNotContainsString('SEED35', json_encode($this->logger->records, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aResolverImplementingBothPortsIsCalledThroughTheNewOne(): void
    {
        $both = new class implements HttpLogRequestContextResolverInterface, HttpRequestContextResolverInterface {
            public function resolve(Request $request, ?TokenInterface $token, string $channel): ?HttpLogRequestContext
            {
                throw new LogicException('the deprecated port is not called');
            }

            public function resolveContext(HttpRequestContext $request, string $channel): HttpLogRequestContext
            {
                $org = $request->header('x-test-org');

                return new HttpLogRequestContext(tenantKey: is_string($org) ? $org : null);
            }
        };

        $request = Request::create('/api/x', 'GET', [], [], [], ['HTTP_X_TEST_ORG' => 'org-b']);
        self::assertEquals(new HttpLogRequestContext(tenantKey: 'org-b'), $this->resolution([$both])->forRequest($request, 'api'));
        self::assertSame([], $this->logger->warnings());
    }
}
