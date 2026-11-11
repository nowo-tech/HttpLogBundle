<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Inbound;

use LogicException;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolverInterface;
use Nowo\HttpLogBundle\Inbound\HttpRequestContext;
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
    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new CollectingLogger();
    }

    /** @param list<HttpLogRequestContextResolverInterface|HttpRequestContextResolverInterface> $resolvers */
    private function resolution(array $resolvers, ?TokenStorage $storage = null): HttpLogRequestContextResolution
    {
        return new HttpLogRequestContextResolution($resolvers, ['Authorization', 'Cookie', 'X-Api-Key'], ['token', 'api_key'], $this->logger, $storage, null, null, ['X-Beacon-Token']);
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
            'HTTP_AUTHORIZATION'  => 'Basic ' . base64_encode('client:SEED33secret'),
            'HTTP_X_API_KEY'      => 'SEED30placeholder',
            'HTTP_X_BEACON_TOKEN' => 'SEED36beacon',
            'HTTP_X_TEST_ORG'     => 'org-a',
            'HTTP_ACCEPT'         => 'application/json',
        ]);
        $request->attributes->add([
            '_route'        => 'api_task',
            '_route_params' => ['id' => '42', 'nested' => ['x']],
            'api_token_id'  => 'pt-7',
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
        self::assertSame(['api_token_id' => 'pt-7'], $context->attributes);
        self::assertSame('pt-7', $context->attribute('api_token_id'));
        self::assertSame('org-a', $context->header('X-Test-Org'));
        self::assertSame('application/json', $context->header('accept'));
        foreach (['authorization', 'cookie', 'x-api-key', 'x-beacon-token', 'php-auth-user', 'php-auth-pw'] as $name) {
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
        $token->setAttribute('api_token_id', 'pt-9');
        $token->setAttribute('api_token', new HttpLogRequestContext());
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
        self::assertSame(['api_token_id' => 'pt-9'], $context->tokenAttributes);
        self::assertSame('pt-9', $context->tokenAttribute('api_token_id'));
        self::assertTrue($context->hasTokenAttribute('api_token_id'));
        self::assertFalse($context->hasTokenAttribute('api_token'));

        // a user identifier that is the request's credential is not given to the port either
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('SEED14placeholder', null), 'api'));
        $bearer = Request::create('/api/x', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer SEED14placeholder']);
        $bearer->attributes->set('_firewall_context', 'api');
        $leaky = $this->port(null);
        $this->resolution([$leaky], $storage)->resolve($bearer, 'api');
        self::assertNull(($leaky->contexts[0] ?? null)?->userIdentifier);
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
