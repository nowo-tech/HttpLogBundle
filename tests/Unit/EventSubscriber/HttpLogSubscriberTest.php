<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class HttpLogSubscriberTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $captureConfig = [
        'request_headers'       => true,
        'request_body'          => false,
        'response_headers'      => true,
        'client_ip'             => true,
        'user'                  => false,
        'max_body_bytes'        => 65536,
        'response_body_by_type' => [
            'html' => false, 'json' => true, 'soap' => false, 'xml' => false,
            'text' => false, 'binary' => false, 'other' => false,
        ],
    ];

    public function testRecordsOnTerminateWhenEnabled(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->willReturn(new Envelope(new stdClass()));

        $recorder   = $this->createRecorder($messageBus);
        $subscriber = $this->createSubscriber($recorder);
        $this->dispatchTerminate($subscriber);
    }

    public function testSkipsWhenDisabled(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $subscriber = $this->createSubscriber($this->createRecorder($messageBus), enabled: false);
        $this->dispatchTerminate($subscriber);
    }

    public function testSkipsIgnoredRoute(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $subscriber = $this->createSubscriber(
            $this->createRecorder($messageBus),
            ignoreRoutes: ['nowo_http_log_*'],
        );
        $this->dispatchTerminate($subscriber, route: 'nowo_http_log_index');
    }

    public function testSkipsIgnoredPathPrefix(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $subscriber = $this->createSubscriber(
            $this->createRecorder($messageBus),
            ignorePathPrefixes: ['/admin/http-log'],
        );
        $this->dispatchTerminate($subscriber, path: '/admin/http-log/1');
    }

    public function testSkipsWhenEnvironmentNotMatched(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $subscriber = $this->createSubscriber(
            $this->createRecorder($messageBus),
            environments: ['prod'],
            kernelEnvironment: 'dev',
        );
        $this->dispatchTerminate($subscriber);
    }

    public function testUsesRequestIdFromHeader(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (PersistHttpLogMessage $message): bool => ($message->payload['requestId'] ?? null) === 'req-123'))
            ->willReturn(new Envelope(new stdClass()));

        $subscriber = $this->createSubscriber($this->createRecorder($messageBus));
        $this->dispatchTerminate($subscriber, requestIdHeader: 'req-123');
    }

    public function testSubRequestSkippedUnlessConfigured(): void
    {
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $subscriber = $this->createSubscriber($this->createRecorder($messageBus), trackSubRequests: false);
        $kernel     = $this->createMock(HttpKernelInterface::class);
        $request    = Request::create('/sub');
        $response   = new Response('ok');

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        $subscriber->onKernelTerminate(new TerminateEvent($kernel, $request, $response));
    }

    public function testSubscribedEvents(): void
    {
        self::assertArrayHasKey('kernel.request', HttpLogSubscriber::getSubscribedEvents());
        self::assertArrayHasKey('kernel.terminate', HttpLogSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function samplingRateZeroSkipsRecording(): void
    {
        $recorder = $this->createMock(HttpLogRecorder::class);
        $recorder->expects(self::never())->method('record');

        $subscriber = $this->createSubscriber($recorder, samplingRate: 0.0);
        $this->dispatchTerminate($subscriber);
    }

    #[Test]
    public function fractionalSamplingExecutesSamplingBranch(): void
    {
        $recorder = $this->createMock(HttpLogRecorder::class);
        $recorder->expects(self::atMost(1))->method('record');

        $subscriber = $this->createSubscriber($recorder, samplingRate: 0.5);
        $this->dispatchTerminate($subscriber);

        self::assertTrue(true);
    }

    #[Test]
    public function missingStartTimeSkipsRecording(): void
    {
        $recorder = $this->createMock(HttpLogRecorder::class);
        $recorder->expects(self::never())->method('record');

        $subscriber = $this->createSubscriber($recorder);
        $kernel     = $this->createMock(HttpKernelInterface::class);
        $request    = Request::create('/no-start-time');
        $response   = new Response('ok');

        $subscriber->onKernelTerminate(new TerminateEvent($kernel, $request, $response));
    }

    private function createRecorder(MessageBusInterface $messageBus): HttpLogRecorder
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);

        return new HttpLogRecorder(
            $entityManager,
            $messageBus,
            new NativeClock(),
            new NullLogger(),
            new HttpLogRedactor([], [], []),
            new CapturePolicy($this->captureConfig),
            new ContentTypeClassifier(),
            true,
            $this->captureConfig,
        );
    }

    private function createSubscriber(
        HttpLogRecorder $recorder,
        bool $enabled = true,
        array $environments = [],
        float $samplingRate = 1.0,
        bool $trackSubRequests = false,
        array $ignoreRoutes = [],
        array $ignorePathPrefixes = [],
        string $kernelEnvironment = 'dev',
    ): HttpLogSubscriber {
        return new HttpLogSubscriber(
            $recorder,
            $enabled,
            $environments,
            $samplingRate,
            $trackSubRequests,
            $ignoreRoutes,
            $ignorePathPrefixes,
            $kernelEnvironment,
        );
    }

    private function dispatchTerminate(
        HttpLogSubscriber $subscriber,
        string $path = '/api/items',
        ?string $route = 'demo_route',
        ?string $requestIdHeader = null,
    ): void {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($path);
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }
        if ($requestIdHeader !== null) {
            $request->headers->set('X-Request-Id', $requestIdHeader);
        }
        $response = new Response('{"ok":true}', 200, ['Content-Type' => 'application/json']);

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelTerminate(new TerminateEvent($kernel, $request, $response));
    }
}
