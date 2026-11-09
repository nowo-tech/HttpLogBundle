<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Inbound;

use Nowo\HttpLogBundle\Inbound\HttpLogRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolverInterface;
use Nowo\HttpLogBundle\Inbound\InboundChannel;
use Nowo\HttpLogBundle\Inbound\InboundChannelMatcher;
use Nowo\HttpLogBundle\Inbound\InboundRequestRecorder;
use Nowo\HttpLogBundle\Inbound\RouteTemplateMap;
use Nowo\HttpLogBundle\Tests\Support\UsageTestRig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Config\ConfigCacheFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

use function array_column;
use function array_map;
use function bin2hex;
use function count;
use function json_encode;
use function random_bytes;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

/**
 * HL-1 §4.1 / §6: what a named-channel capture holds, when it is a row and when only a rollup count; errors always
 * kept whatever the sampling; nothing ever thrown.
 */
final class InboundRequestRecorderTest extends TestCase
{
    private UsageTestRig $rig;

    protected function setUp(): void
    {
        $this->rig = new UsageTestRig();
    }

    private function recorder(bool $rollup = false, bool $web = false, ?InboundChannelMatcher $matcher = null): InboundRequestRecorder
    {
        $channels = [
            'api' => ['path_prefixes' => ['/api'], 'sampling_rate' => 0.0, 'error_status_min' => 500, 'client_ip' => 'full', 'user' => false],
        ];
        $matcher ??= InboundChannelMatcher::fromConfig($channels);
        $router = $this->createStub(RouterInterface::class);
        $routes = new RouteCollection();
        $routes->add('api_task', new Route('/api/tasks/{id<\d+>}'));
        $routes->add('usage_hello', new Route('/hello'));
        $router->method('getRouteCollection')->willReturn($routes);
        $map      = new RouteTemplateMap($router, $matcher, new ConfigCacheFactory(false), sys_get_temp_dir() . '/http-log-recorder-' . bin2hex(random_bytes(6)));
        $resolver = new class implements HttpLogRequestContextResolverInterface {
            public function resolve(Request $request, ?TokenInterface $token, string $channel): HttpLogRequestContext
            {
                return new HttpLogRequestContext('personal_token', 'pt-1', 'org-a', 'prj-1');
            }
        };

        return new InboundRequestRecorder($matcher, $map, new HttpLogRequestContextResolution([$resolver]), $this->rig->buffer, $this->rig->clock, $this->rig->logger, $rollup, $web);
    }

    private function request(string $path = '/api/tasks/9', ?string $route = 'api_task'): Request
    {
        $request = Request::create($path . '?token=SEED15placeholder', 'GET', [], [], [], ['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_REQUEST_ID' => 'SEED24placeholder', 'HTTP_HOST' => 'API.example.test:8443']);
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        return $request;
    }

    /** @return list<array<string, mixed>> */
    private function payloads(): array
    {
        $this->rig->buffer->flush();

        return array_column($this->rig->messages, 'payload');
    }

    #[Test]
    public function errorsAreRowsWhateverTheSamplingAndSuccessesAreCountOnly(): void
    {
        $recorder = $this->recorder(rollup: true);
        $channel  = $recorder->match($this->request());
        self::assertInstanceOf(InboundChannel::class, $channel);

        $recorder->record($channel, $this->request(), new Response('{"a":1}', 200, ['Content-Type' => 'application/json; charset=utf-8']), 12.5);
        $recorder->record($channel, $this->request(), new Response('', 404), 3.0);
        $recorder->record($channel, $this->request(), new Response('', 503, ['Content-Length' => '42']), 3.0);
        $failed = $this->request();
        $failed->attributes->set(InboundRequestRecorder::EXCEPTION_ATTRIBUTE, true);
        $recorder->record($channel, $failed, new Response('', 404), 3.0);

        $payloads = $this->payloads();
        self::assertSame([false, false, null, null], array_map(static fn (array $p): mixed => $p['row'] ?? null, $payloads), '200 sampled out, 404 below error_status_min: count-only; 503 and the exception: rows');
        self::assertSame([null, null, null, 'exception'], array_column($payloads, 'errorKind'));
        self::assertSame([7, 0, 42, 0], array_column($payloads, 'responseBytes'));

        $first = $payloads[0];
        self::assertSame(['api', 'GET', 'http', 'api.example.test:8443', '/api/tasks/{id}', 'api_task', 200, 'application/json'], [$first['channel'], $first['method'], $first['scheme'] ?? null, $first['host'], $first['path'], $first['routeName'], $first['statusCode'], $first['contentType']]);
        self::assertSame(['personal_token', 'pt-1', 'org-a', 'prj-1', true], [$first['credentialKind'], $first['credentialId'], $first['tenantKey'], $first['projectKey'], $first['rollup']]);
        self::assertArrayNotHasKey('clientIp', $first, 'a count-only event carries no IP');
        self::assertSame('198.51.100.7', $payloads[2]['clientIp'], 'client_ip: full on a row');
        self::assertArrayNotHasKey('userIdentifier', $payloads[2], 'user: false');
        self::assertNotSame('SEED24placeholder', $first['eventId']);
        self::assertStringNotContainsString('SEED', json_encode($payloads, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function withoutTheRollupAnUnsampledSuccessIsNothing(): void
    {
        $recorder = $this->recorder();
        $channel  = new InboundChannel('api', ['/api'], samplingRate: 0.0);

        $recorder->record($channel, $this->request(), new Response('ok'), 1.0);
        self::assertSame([], $this->payloads());

        $recorder->record(new InboundChannel('api', ['/api'], samplingRate: 1.0, rollup: false), $this->request(), new Response('ok'), 1.0);
        $recorder->record(new InboundChannel('api', ['/api'], samplingRate: 0.5), $this->request(), new Response('ok'), 1.0);
        $payloads = $this->payloads();
        self::assertGreaterThanOrEqual(1, count($payloads));
        self::assertArrayNotHasKey('rollup', $payloads[0]);
    }

    #[Test]
    public function routesOutsideTheMapAndStreamedBodiesStoreNoPathOrSize(): void
    {
        $recorder = $this->recorder();
        $channel  = new InboundChannel('api', ['/api']);

        $recorder->record($channel, $this->request('/api/raw/SEED16placeholder', null), new StreamedResponse(static function (): void {
        }), 1.0);
        $recorder->record($channel, $this->request('/api/hello', 'usage_hello'), new BinaryFileResponse(__FILE__), 1.0);

        $payloads = $this->payloads();
        self::assertSame(['{unrouted}', '{unrouted}'], array_column($payloads, 'path'), 'usage_hello is not selected by the channel');
        self::assertNull($payloads[0]['responseBytes']);
        self::assertSame('198.51.100.0', $payloads[0]['clientIp']);
    }

    #[Test]
    public function aFailingMatcherOrCaptureIsLoggedAndSwallowed(): void
    {
        $matcher = $this->createMock(InboundChannelMatcher::class);
        $matcher->method('match')->willThrowException(new RuntimeException('SEED36'));
        $matcher->method('selectsRoute')->willReturn(true);
        $recorder = $this->recorder(matcher: $matcher);
        self::assertNull($recorder->match($this->request()));

        $response = $this->createStub(Response::class);
        $response->method('getStatusCode')->willThrowException(new RuntimeException('SEED37'));
        $recorder->record(new InboundChannel('api', ['/api']), $this->request(), $response, 1.0);
        $recorder->countWeb($this->request('/hello', 'usage_hello'), $response, 1.0);

        self::assertCount(3, $this->rig->logs);
        self::assertSame([RuntimeException::class, RuntimeException::class, RuntimeException::class], array_column(array_column($this->rig->logs, 'context'), 'exception_class'));
        self::assertStringNotContainsString('SEED', json_encode($this->rig->logs, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function webRequestsAreCountedOnlyWithRollupWeb(): void
    {
        self::assertFalse($this->recorder(rollup: true)->countsWeb());
        self::assertFalse($this->recorder(web: true)->countsWeb());
        $recorder = $this->recorder(rollup: true, web: true);
        self::assertTrue($recorder->countsWeb());

        $request = $this->request('/hello', 'usage_hello');
        $request->attributes->set(InboundRequestRecorder::EXCEPTION_ATTRIBUTE, true);
        $recorder->countWeb($request, new Response('hello', 500), 4.0);
        $recorder->countWeb($this->request('/hello', 'usage_hello'), new Response('hello'), 4.0);

        $payloads = $this->payloads();
        self::assertSame(['web', 'web'], array_column($payloads, 'channel'));
        self::assertSame([false, false], array_column($payloads, 'row'));
        self::assertSame(['exception', null], array_column($payloads, 'errorKind'));
        self::assertArrayNotHasKey('clientIp', $payloads[0]);
        self::assertSame('/', $payloads[0]['path']);
    }

    #[Test]
    public function anUntrustedHostIsStoredAsNullAndTheRequestStillRecorded(): void
    {
        Request::setTrustedHosts(['^api\\.example\\.test$']);

        try {
            $request = Request::create('/api/tasks/1', 'GET', [], [], [], ['HTTP_HOST' => 'evil.example']);
            $request->attributes->set('_route', 'api_task');
            $this->recorder()->record(new InboundChannel('api', ['/api']), $request, new Response('', 400), 1.0);
        } finally {
            Request::setTrustedHosts([]);
        }

        $payloads = $this->payloads();
        self::assertCount(1, $payloads);
        self::assertNull($payloads[0]['host']);
        self::assertSame(400, $payloads[0]['statusCode']);
    }
}
