<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Correlation;

use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\CorrelationContract\NullCorrelationIdProvider;
use Nowo\HttpLogBundle\Correlation\CorrelationIds;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Nowo\HttpLogBundle\Tests\Support\MutableCorrelationIdProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function str_repeat;

final class CorrelationIdsTest extends TestCase
{
    private const TRACEPARENT = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    #[Test]
    public function withoutAProviderThereIsNoProviderId(): void
    {
        self::assertNull((new CorrelationIds())->fromProvider());
        self::assertNull((new CorrelationIds(new NullCorrelationIdProvider()))->fromProvider());
    }

    #[Test]
    public function theProviderIdIsReadAtEveryCall(): void
    {
        $provider = new MutableCorrelationIdProvider('req-1');
        $ids      = new CorrelationIds($provider);

        self::assertSame('req-1', $ids->fromProvider());
        $provider->id = 'req-2';
        self::assertSame('req-2', $ids->fromProvider(), 'never memoised (worker mode)');
    }

    #[Test]
    public function aFailingProviderGivesNoId(): void
    {
        $provider = $this->createStub(CorrelationIdProviderInterface::class);
        $provider->method('current')->willThrowException(new RuntimeException('boom'));

        self::assertNull((new CorrelationIds($provider))->fromProvider());
    }

    #[Test]
    public function currentPrefersTheProviderThenTheMainRequestId(): void
    {
        $stack   = new RequestStack();
        $request = Request::create('/');
        $request->attributes->set(HttpLogSubscriber::ATTR_REQUEST_ID, 'abc123');
        $stack->push($request);

        self::assertSame('abc123', (new CorrelationIds(null, $stack))->current());
        $request->attributes->set(HttpLogSubscriber::ATTR_CORRELATION_ID, 'kept-provider-id');
        self::assertSame('kept-provider-id', (new CorrelationIds(null, $stack))->current(), 'the provider id kept for the request first');
        $request->attributes->remove(HttpLogSubscriber::ATTR_CORRELATION_ID);
        self::assertSame('from-provider', (new CorrelationIds(new MutableCorrelationIdProvider('from-provider'), $stack))->current());

        $request->attributes->set(HttpLogSubscriber::ATTR_REQUEST_ID, 'not valid!');
        self::assertNull((new CorrelationIds(null, $stack))->current(), 'only a valid id');
        $request->attributes->set(HttpLogSubscriber::ATTR_REQUEST_ID, 42);
        self::assertNull((new CorrelationIds(null, $stack))->current());
        self::assertNull((new CorrelationIds(null, new RequestStack()))->current(), 'outside a request');
        self::assertNull((new CorrelationIds())->current());
    }

    #[Test]
    public function upstreamIsIgnoredWhenTheRequestDoesNotComeFromATrustedProxy(): void
    {
        $request = $this->request(['X-Request-Id' => 'up-1']);

        self::assertNull((new CorrelationIds())->upstream($request), 'no trusted proxy configured');

        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        self::assertNull((new CorrelationIds())->upstream($request), 'REMOTE_ADDR is not the trusted proxy');
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('upstreamCases')]
    public function upstreamFromATrustedProxy(array $headers, ?string $expected): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

        self::assertSame($expected, (new CorrelationIds())->upstream($this->request($headers)));
    }

    /** @return iterable<string, array{0: array<string, string>, 1: string|null}> */
    public static function upstreamCases(): iterable
    {
        yield 'X-Request-Id' => [['X-Request-Id' => 'up-1'], 'up-1'];
        yield 'X-Correlation-Id' => [['X-Correlation-Id' => 'corr:2'], 'corr:2'];
        yield 'traceparent trace-id' => [['traceparent' => self::TRACEPARENT], '4bf92f3577b34da6a3ce929d0e0e4736'];
        yield 'first valid header wins' => [['X-Request-Id' => 'first', 'traceparent' => self::TRACEPARENT], 'first'];
        yield 'invalid X-Request-Id falls through' => [['X-Request-Id' => "bad value\n", 'traceparent' => self::TRACEPARENT], '4bf92f3577b34da6a3ce929d0e0e4736'];
        yield 'too long' => [['X-Request-Id' => str_repeat('a', 65)], null];
        yield 'invalid traceparent' => [['traceparent' => '00-00000000000000000000000000000000-00f067aa0ba902b7-01'], null];
        yield 'none' => [[], null];
    }

    #[Test]
    public function upstreamReadsOnlyTheConfiguredHeaders(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        $ids = new CorrelationIds(upstreamHeaders: ['TraceParent']);

        self::assertNull($ids->upstream($this->request(['X-Request-Id' => 'up-1'])));
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $ids->upstream($this->request(['traceparent' => self::TRACEPARENT])), 'header names are case-insensitive');
    }

    /** @param array<string, string> $headers */
    private function request(array $headers): Request
    {
        $request = Request::create('/', server: ['REMOTE_ADDR' => '127.0.0.1']);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }
}
