<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\HttpLogBundle\Correlation\CorrelationIds;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use Nowo\HttpLogBundle\Tests\Support\MutableCorrelationIdProvider;
use Nowo\HttpLogBundle\Tests\Support\ThrowingAfterFirstCallProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * 1.27 (Beacon spec 235 FR-002 / FR-005): request_id from the host's correlation provider, upstream_id from a trusted
 * proxy only.
 */
final class HttpLogSubscriberCorrelationTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $payloads = [];

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    #[Test]
    public function itListensToTheResponseLate(): void
    {
        self::assertSame(['onKernelResponse', -1024], HttpLogSubscriber::getSubscribedEvents()[KernelEvents::RESPONSE]);
    }

    #[Test]
    public function withoutAProviderTheRequestIdIsGeneratedAsBefore(): void
    {
        $this->handle($this->subscriber(new CorrelationIds()), $this->request(['X-Request-Id' => 'client-id']));

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\z/', (string) $this->payloads[0]['requestId']);
        self::assertArrayNotHasKey('upstreamId', $this->payloads[0], 'columns off: no upstream id');
        self::assertArrayNotHasKey('correlationId', $this->payloads[0], 'no provider: the row\'s own request id is its correlation id');
    }

    #[Test]
    public function theProviderIdBecomesTheCorrelationIdNeverTheRequestId(): void
    {
        $provider = new MutableCorrelationIdProvider('beacon-req-1');
        $this->handle($this->subscriber(new CorrelationIds($provider)), $this->request());

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\z/', (string) $this->payloads[0]['requestId'], 'request_id stays HttpLog\'s own id');
        self::assertSame('beacon-req-1', $this->payloads[0]['correlationId']);
    }

    /** ZDW-HL-M1: a repeated provider id never collapses rows. */
    #[Test]
    public function twoMainRequestsWithTheSameProviderIdGetDistinctRequestIds(): void
    {
        $subscriber = $this->subscriber(new CorrelationIds(new MutableCorrelationIdProvider('chain-1')));
        $this->handle($subscriber, $this->request());
        $this->handle($subscriber, $this->request());

        self::assertCount(2, $this->payloads);
        self::assertNotSame($this->payloads[0]['requestId'], $this->payloads[1]['requestId']);
        self::assertSame(['chain-1', 'chain-1'], [$this->payloads[0]['correlationId'], $this->payloads[1]['correlationId']]);
    }

    #[Test]
    public function aProviderThrowingAtTheResponseKeepsTheIdsAndTheRow(): void
    {
        $provider   = new ThrowingAfterFirstCallProvider('req-early');
        $subscriber = $this->subscriber(new CorrelationIds($provider));
        $request    = $this->request();
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $generated = $request->attributes->get(HttpLogSubscriber::ATTR_REQUEST_ID);
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $subscriber->onKernelTerminate(new TerminateEvent($kernel, $request, new Response()));

        self::assertGreaterThan(1, $provider->calls, 'the provider threw at kernel.response and kernel.terminate');
        self::assertSame($generated, $this->payloads[0]['requestId']);
        self::assertSame('req-early', $this->payloads[0]['correlationId'], 'the id read before the failure is kept');
    }

    /** ZDW-HL-I1: the provider is read again at kernel.terminate. */
    #[Test]
    public function aProviderIdSetOnlyAtTerminateIsStillTheCorrelationId(): void
    {
        $provider   = new MutableCorrelationIdProvider();
        $subscriber = $this->subscriber(new CorrelationIds($provider));
        $request    = $this->request();
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $provider->id = 'at-terminate';
        $subscriber->onKernelTerminate(new TerminateEvent($kernel, $request, new Response()));

        self::assertSame('at-terminate', $this->payloads[0]['correlationId']);
    }

    #[Test]
    public function aProviderIdSetAfterTheRequestListenerWinsAtTheResponse(): void
    {
        $provider   = new MutableCorrelationIdProvider();
        $subscriber = $this->subscriber(new CorrelationIds($provider));
        $request    = $this->request();
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $generated = $request->attributes->get(HttpLogSubscriber::ATTR_REQUEST_ID);
        self::assertIsString($generated);

        $provider->id = 'late-id';
        $subscriber->onKernelResponse(new ResponseEvent($kernel, Request::create('/sub'), HttpKernelInterface::SUB_REQUEST, new Response()));
        self::assertFalse($request->attributes->has(HttpLogSubscriber::ATTR_CORRELATION_ID), 'a sub-request never sets it');

        $subscriber->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
        self::assertSame($generated, $request->attributes->get(HttpLogSubscriber::ATTR_REQUEST_ID), 'request_id never changes');
        $provider->id = null; // the provider cleared its id before HttpLog's terminate listener
        $subscriber->onKernelTerminate(new TerminateEvent($kernel, $request, new Response()));

        self::assertSame($generated, $this->payloads[0]['requestId']);
        self::assertSame('late-id', $this->payloads[0]['correlationId']);
    }

    #[Test]
    public function aResponseWithoutProviderIdKeepsTheGeneratedId(): void
    {
        $subscriber = $this->subscriber(new CorrelationIds(new MutableCorrelationIdProvider()));
        $request    = $this->request();
        $kernel     = $this->createStub(HttpKernelInterface::class);

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $generated = $request->attributes->get(HttpLogSubscriber::ATTR_REQUEST_ID);
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));

        self::assertSame($generated, $request->attributes->get(HttpLogSubscriber::ATTR_REQUEST_ID));
        self::assertFalse($request->attributes->has(HttpLogSubscriber::ATTR_CORRELATION_ID));
    }

    #[Test]
    public function withoutCorrelationTheResponseListenerDoesNothing(): void
    {
        $subscriber = $this->subscriber(null);
        $request    = $this->request();
        $subscriber->onKernelResponse(new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new Response()));

        self::assertFalse($request->attributes->has(HttpLogSubscriber::ATTR_REQUEST_ID));
        self::assertFalse($request->attributes->has(HttpLogSubscriber::ATTR_CORRELATION_ID));
    }

    #[Test]
    public function aTrackedSubRequestKeepsItsOwnGeneratedId(): void
    {
        $subscriber = $this->subscriber(new CorrelationIds(new MutableCorrelationIdProvider('main-id')), trackSubRequests: true, captureUpstream: true);
        $request    = $this->request(['X-Request-Id' => 'up-1']);
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

        $subscriber->onKernelRequest(new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::SUB_REQUEST));

        self::assertNotSame('main-id', $request->attributes->get(HttpLogSubscriber::ATTR_REQUEST_ID));
        self::assertFalse($request->attributes->has(HttpLogSubscriber::ATTR_CORRELATION_ID), 'main request only');
        self::assertFalse($request->attributes->has(HttpLogSubscriber::ATTR_UPSTREAM_ID), 'main request only');
    }

    #[Test]
    public function theUpstreamIdOfATrustedProxyIsRecordedNextToTheOwnId(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        $this->handle($this->subscriber(new CorrelationIds(), captureUpstream: true), $this->request(['X-Request-Id' => 'up-1']));

        self::assertSame('up-1', $this->payloads[0]['upstreamId']);
        self::assertNotSame('up-1', $this->payloads[0]['requestId'], 'never replaces the request id');
    }

    #[Test]
    public function anUntrustedOrInvalidUpstreamIdIsDropped(): void
    {
        $this->handle($this->subscriber(new CorrelationIds(), captureUpstream: true), $this->request(['X-Request-Id' => 'up-1']));
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        $this->handle($this->subscriber(new CorrelationIds(), captureUpstream: true), $this->request(['X-Request-Id' => '<script>']));

        self::assertCount(2, $this->payloads);
        self::assertArrayNotHasKey('upstreamId', $this->payloads[0], 'not from a trusted proxy');
        self::assertArrayNotHasKey('upstreamId', $this->payloads[1], 'not a valid id');
    }

    #[Test]
    public function upstreamIsNotReadWhileTheColumnsAreOff(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        $request = $this->request(['X-Request-Id' => 'up-1']);
        $this->handle($this->subscriber(new CorrelationIds()), $request);

        self::assertFalse($request->attributes->has(HttpLogSubscriber::ATTR_UPSTREAM_ID));
    }

    private function handle(HttpLogSubscriber $subscriber, Request $request): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
        $subscriber->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok')));
    }

    /** @param array<string, string> $headers */
    private function request(array $headers = []): Request
    {
        $request = Request::create('/orders', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $request->attributes->set('_route', 'orders');
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    private function subscriber(?CorrelationIds $correlation, bool $trackSubRequests = false, bool $captureUpstream = false): HttpLogSubscriber
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            self::assertInstanceOf(PersistHttpLogMessage::class, $message);
            $this->payloads[] = $message->payload;

            return new Envelope($message);
        });
        $config   = ['request_headers' => false, 'response_headers' => false, 'request_body' => false, 'client_ip' => false, 'user' => false, 'max_body_bytes' => 1024];
        $recorder = new HttpLogRecorder(
            $this->createStub(EntityManagerInterface::class),
            $bus,
            new NativeClock(),
            new NullLogger(),
            new HttpLogRedactor([], [], []),
            new CapturePolicy($config),
            new ContentTypeClassifier(),
            true,
            $config,
        );

        return new HttpLogSubscriber($recorder, true, [], 1.0, $trackSubRequests, [], [], 'test', correlation: $correlation, captureUpstream: $captureUpstream);
    }
}
