<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Inbound;

use LogicException;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolverInterface;
use Nowo\HttpLogBundle\Tests\Support\CollectingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\Security\FirewallConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\LazyResponseException;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_column;
use function base64_encode;
use function is_string;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * HL-1 §6.3: the resolver hook (first non-null result, failures skipped) and its guard (HL-1 §4.3 case 12): a value
 * that is, contains or starts a credential the request carried is dropped with a warning that never holds the value.
 */
final class HttpLogRequestContextResolutionTest extends TestCase
{
    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new CollectingLogger();
    }

    /** @param list<HttpLogRequestContextResolverInterface> $resolvers */
    private function resolution(array $resolvers, ?TokenStorageInterface $storage = null, ?Security $security = null, ?TokenStorageInterface $untracked = null): HttpLogRequestContextResolution
    {
        return new HttpLogRequestContextResolution($resolvers, ['Authorization', 'Cookie', 'X-Api-Key'], ['token', 'api_key'], $this->logger, $storage, $security, $untracked);
    }

    private function returning(?HttpLogRequestContext $context): HttpLogRequestContextResolverInterface
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

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function leaks(): iterable
    {
        yield 'the bearer value' => ['credentialId', 'SEED14placeholder'];
        yield 'contains the bearer' => ['credentialId', 'id-SEED14placeholder'];
        yield 'a prefix of the bearer' => ['credentialId', 'SEED14'];
        yield 'the whole header' => ['tenantKey', 'SEED14placeholder'];
        yield 'an api key header' => ['tenantKey', 'SEED30placeholder'];
        yield 'a cookie' => ['credentialKind', 'SEED31cookie'];
        yield 'a redacted query param (case-insensitive name)' => ['projectKey', 'SEED15placeholder'];
        yield 'a redacted query param in a list' => ['projectKey', 'SEED32listed'];
        yield 'not an opaque key' => ['credentialId', 'has space'];
        yield 'too long' => ['credentialKind', 'a-credential-kind-of-forty-characters-xx'];
    }

    #[Test]
    #[DataProvider('leaks')]
    public function aLeakingOrInvalidValueIsDroppedWithAWarning(string $field, string $value): void
    {
        $context = new HttpLogRequestContext(...[$field => $value]);
        $request = Request::create('/api/x?TOKEN=SEED15placeholder&api_key[]=SEED32listed', 'GET', [], ['sid' => 'SEED31cookie'], [], ['HTTP_AUTHORIZATION' => 'Bearer SEED14placeholder', 'HTTP_X_API_KEY' => 'SEED30placeholder']);

        $resolved = $this->resolution([$this->returning($context)])->resolve($request, 'api');

        self::assertEquals(new HttpLogRequestContext(), $resolved);
        self::assertCount(1, $this->logger->warnings());
        self::assertStringNotContainsString($value, json_encode($this->logger->records, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function basicCredentialsAreGuardedDecodedAndClean(): void
    {
        $request    = Request::create('/api/x', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('client-7:SEED33secret')]);
        $resolution = $this->resolution([$this->returning(new HttpLogRequestContext('dsn_key', 'SEED33secret', 'org-a', 'client-7'))]);

        self::assertContains('client-7:SEED33secret', $resolution->secrets($request));
        // the password is a secret; the user name starts the decoded pair, so it is dropped too (fails closed)
        self::assertEquals(new HttpLogRequestContext('dsn_key', null, 'org-a'), $resolution->resolve($request, 'api'));

        $noColon = Request::create('/api/x', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('onlyuser')]);
        self::assertContains('onlyuser', $resolution->secrets($noColon));
        $bare = Request::create('/api/x', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'SEED34bare']);
        self::assertSame(['SEED34bare'], $resolution->secrets($bare));
    }

    #[Test]
    public function theFirstNonNullResultWinsAndAFailingResolverIsSkipped(): void
    {
        $throwing = new class implements HttpLogRequestContextResolverInterface {
            public function resolve(Request $request, ?TokenInterface $token, string $channel): ?HttpLogRequestContext
            {
                throw new LogicException('SEED35 in a message');
            }
        };
        $resolution = $this->resolution([$throwing, $this->returning(null), $this->returning(new HttpLogRequestContext('personal_token', 'pt-1', 'org-a')), $this->returning(new HttpLogRequestContext('other'))]);
        $request    = Request::create('/api/x');

        self::assertEquals(new HttpLogRequestContext('personal_token', 'pt-1', 'org-a'), $resolution->forRequest($request, 'api'));
        self::assertSame([LogicException::class], array_column(array_column($this->logger->warnings(), 'context'), 'exception_class'));
        self::assertStringNotContainsString('SEED35', json_encode($this->logger->records, JSON_THROW_ON_ERROR));

        // resolved once per request
        self::assertSame($resolution->forRequest($request, 'api'), $request->attributes->get(HttpLogRequestContextResolution::ATTRIBUTE));
        self::assertCount(1, $this->logger->warnings());

        self::assertEquals(new HttpLogRequestContext(), $this->resolution([$this->returning(null)])->resolve($request, 'api'));
        self::assertEquals(new HttpLogRequestContext(), $this->resolution([])->resolve($request, 'api'));
    }

    #[Test]
    public function theTokenAndUserAreTrustedOnlyBehindASecuredFirewall(): void
    {
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('ana@example.test', null), 'main'));
        $request = Request::create('/api/x');

        $plain = $this->resolution([], $storage);
        self::assertNull($plain->token($request), 'no firewall context: a worker may hold the previous token');
        $request->attributes->set('_firewall_context', 'security.firewall.map.context.api');
        self::assertSame('ana@example.test', $plain->userIdentifier($request));

        $security = $this->createStub(Security::class);
        $security->method('getFirewallConfig')->willReturn(new FirewallConfig('api', 'checker', null, false));
        self::assertNull($this->resolution([], $storage, $security)->token($request), 'security disabled on that firewall');
        self::assertNull($this->resolution([])->token($request), 'no token storage');

        // a user identifier that is the bearer token itself is never stored
        $storage->setToken(new UsernamePasswordToken(new InMemoryUser('SEED14placeholder', null), 'api'));
        $bearer = Request::create('/api/x', 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer SEED14placeholder']);
        $bearer->attributes->set('_firewall_context', 'api');
        self::assertNull($plain->userIdentifier($bearer));
        self::assertSame('user_identifier', $this->logger->warnings()[0]['context']['field'] ?? null);
        $storage->setToken(null);
        self::assertNull($plain->userIdentifier($bearer));
    }

    #[Test]
    public function resolversRunEarlyOnlyWhenThatCannotStartALazyAuthentication(): void
    {
        $resolver = $this->returning(null);
        self::assertFalse($this->resolution([])->canResolveEarly(), 'no resolver');
        self::assertTrue($this->resolution([$resolver])->canResolveEarly(), 'no security at all');
        self::assertFalse($this->resolution([$resolver], new TokenStorage())->canResolveEarly(), 'a token storage without the untracked one: cannot tell');

        $untracked = new TokenStorage();
        self::assertTrue($this->resolution([$resolver], $untracked, null, $untracked)->canResolveEarly(), 'eager firewall');
        $untracked->setInitializer(static function (): void {
        });
        self::assertFalse($this->resolution([$resolver], $untracked, null, $untracked)->canResolveEarly(), 'lazy firewall not authenticated yet');
    }

    #[Test]
    public function shortRequestValuesAreSecretsOnlyByExactEquality(): void
    {
        $request    = Request::create('/api/x?token=-&api_key=o', 'GET', [], ['a' => 'e'], [], ['HTTP_X_API_KEY' => '1']);
        $context    = new HttpLogRequestContext('personal_token', '1001', 'org-0e1-uuid', 'prj-o');
        $resolution = $this->resolution([self::returning($context)]);
        self::assertEquals($context, $resolution->resolve($request, 'api'));
        self::assertSame([], $this->logger->warnings());

        // a short real credential is still never stored verbatim
        self::assertEquals(new HttpLogRequestContext('personal_token', null, 'org-a'), $this->resolution([self::returning(new HttpLogRequestContext('personal_token', '1', 'org-a'))])->resolve($request, 'api'));
    }

    #[Test]
    public function anEmptyEarlyResultIsResolvedAgainAtTerminate(): void
    {
        // a controller that authenticates itself (security: false) sets what the resolver reads
        $resolver = new class implements HttpLogRequestContextResolverInterface {
            public int $calls = 0;

            public function resolve(Request $request, ?TokenInterface $token, string $channel): ?HttpLogRequestContext
            {
                ++$this->calls;
                $key = $request->attributes->get('api_key_id');

                return is_string($key) ? new HttpLogRequestContext('dsn_key', $key, 'org-a') : null;
            }
        };
        $resolution = $this->resolution([$resolver]);
        $request    = Request::create('/api/x');

        self::assertEquals(new HttpLogRequestContext(), $resolution->resolveEarly($request, 'api'));
        self::assertFalse($request->attributes->has(HttpLogRequestContextResolution::ATTRIBUTE));
        $request->attributes->set('api_key_id', 'key-7');
        self::assertEquals(new HttpLogRequestContext('dsn_key', 'key-7', 'org-a'), $resolution->forRequest($request, 'api'));
        self::assertSame(2, $resolver->calls);

        // a non-empty early result is kept
        $other = Request::create('/api/x');
        $other->attributes->set('api_key_id', 'key-8');
        $resolution->resolveEarly($other, 'api');
        $resolution->resolveEarly($other, 'api');
        $resolution->forRequest($other, 'api');
        self::assertSame(3, $resolver->calls);
    }

    #[Test]
    public function aDecoratedStorageAndALateLazyFailureNeverBreakTheRequest(): void
    {
        $decorated = $this->createStub(TokenStorageInterface::class);
        $resolver  = self::returning(new HttpLogRequestContext(null, null, 'org-a'));
        self::assertFalse($this->resolution([$resolver], $decorated, null, $decorated)->canResolveEarly(), 'cannot tell a pending lazy authentication');

        $lazy = $this->createStub(TokenStorageInterface::class);
        $lazy->method('getToken')->willThrowException(new LazyResponseException(new Response('', 401)));
        $request = Request::create('/api/x');
        $request->attributes->set('_firewall_context', 'main');
        $resolution = $this->resolution([$resolver], $lazy);
        self::assertEquals(new HttpLogRequestContext(null, null, 'org-a'), $resolution->resolve($request, 'api'), 'at terminate: a null token');
        self::assertNull($resolution->userIdentifier($request));

        $this->expectException(LazyResponseException::class);
        $resolution->resolve($request, 'api', false);
    }
}
