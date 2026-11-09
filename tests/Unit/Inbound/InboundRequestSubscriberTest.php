<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Inbound;

use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Context\HttpLogFrame;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolverInterface;
use Nowo\HttpLogBundle\Inbound\InboundChannelMatcher;
use Nowo\HttpLogBundle\Inbound\InboundRequestRecorder;
use Nowo\HttpLogBundle\Inbound\InboundRequestSubscriber;
use Nowo\HttpLogBundle\Inbound\RouteTemplateCacheWarmer;
use Nowo\HttpLogBundle\Inbound\RouteTemplateMap;
use Nowo\HttpLogBundle\Tests\Support\CollectingLogger;
use Nowo\HttpLogBundle\Tests\Support\UsageTestRig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Config\ConfigCacheFactory;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\LazyResponseException;

use function bin2hex;
use function clearstatcache;
use function file_put_contents;
use function random_bytes;
use function sys_get_temp_dir;
use function time;
use function touch;

/**
 * HL-1 §6.4: the request frame of a named channel is pushed before the controller and popped at terminate; a kernel
 * exception marks the request; the route template map is written by the warmer and rebuilt in debug.
 */
final class InboundRequestSubscriberTest extends TestCase
{
    private HttpLogContext $context;

    private CollectingLogger $logger;

    protected function setUp(): void
    {
        $this->context = new HttpLogContext();
        $this->logger  = new CollectingLogger();
    }

    private function subscriber(?HttpLogRequestContext $resolved, ?HttpLogRequestContextResolution $resolution = null): InboundRequestSubscriber
    {
        $resolver = new class($resolved) implements HttpLogRequestContextResolverInterface {
            public function __construct(private readonly ?HttpLogRequestContext $context)
            {
            }

            public function resolve(Request $request, ?TokenInterface $token, string $channel): ?HttpLogRequestContext
            {
                return $this->context;
            }
        };
        $resolution ??= new HttpLogRequestContextResolution([$resolver]);
        $rig     = new UsageTestRig();
        $matcher = InboundChannelMatcher::fromConfig(['api' => ['path_prefixes' => ['/api']]]);
        $map     = new RouteTemplateMap($this->createStub(RouterInterface::class), $matcher, new ConfigCacheFactory(false), sys_get_temp_dir());

        return new InboundRequestSubscriber(new InboundRequestRecorder($matcher, $map, $resolution, $rig->buffer, $rig->clock), $resolution, $this->context, $this->logger);
    }

    private function arguments(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): ControllerArgumentsEvent
    {
        return new ControllerArgumentsEvent($this->createStub(HttpKernelInterface::class), static fn (): Response => new Response(), [], $request, $type);
    }

    #[Test]
    public function theTenantFrameLastsFromTheControllerToTerminate(): void
    {
        self::assertSame([-1024, 4096, -1536], [InboundRequestSubscriber::getSubscribedEvents()[KernelEvents::CONTROLLER_ARGUMENTS][1], InboundRequestSubscriber::getSubscribedEvents()[KernelEvents::EXCEPTION][1], InboundRequestSubscriber::getSubscribedEvents()[KernelEvents::TERMINATE][1]]);
        $subscriber = $this->subscriber(new HttpLogRequestContext(null, null, 'org-a', 'prj-1'));
        $this->context->push(new HttpLogFrame(null, null, 'msg-1'));
        $request = Request::create('/api/sync');

        $subscriber->onControllerArguments($this->arguments($request));
        $subscriber->onControllerArguments($this->arguments($request)); // a second call pushes nothing
        self::assertSame(2, $this->context->depth());
        self::assertSame(['org-a', 'prj-1', 'msg-1'], [$this->context->current()->tenantKey, $this->context->current()->projectKey, $this->context->current()->messengerMessageId]);

        $subscriber->onTerminate(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response()));
        self::assertSame(1, $this->context->depth());
        self::assertFalse($request->attributes->has(InboundRequestSubscriber::FRAME_ATTRIBUTE));
        $subscriber->onTerminate(new TerminateEvent($this->createStub(HttpKernelInterface::class), Request::create('/api/x'), new Response()));
        self::assertSame(1, $this->context->depth());
    }

    #[Test]
    public function noFrameWithoutATenantOutsideTheChannelsOrForSubRequests(): void
    {
        $this->subscriber(new HttpLogRequestContext('personal_token', 'pt-1'))->onControllerArguments($this->arguments(Request::create('/api/x')));
        $this->subscriber(new HttpLogRequestContext(null, null, 'org-a'))->onControllerArguments($this->arguments(Request::create('/hello')));
        $this->subscriber(new HttpLogRequestContext(null, null, 'org-a'))->onControllerArguments($this->arguments(Request::create('/api/x'), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(0, $this->context->depth());
    }

    #[Test]
    public function aFailureIsLoggedButALazyAuthenticationResponseWins(): void
    {
        $failing = $this->createMock(HttpLogRequestContextResolution::class);
        $failing->method('canResolveEarly')->willReturn(true);
        $failing->method('forRequest')->willThrowException(new RuntimeException('SEED38'));
        $this->subscriber(null, $failing)->onControllerArguments($this->arguments(Request::create('/api/x')));
        self::assertSame(RuntimeException::class, $this->logger->warnings()[0]['context']['exception_class'] ?? null);

        $lazy = $this->createMock(HttpLogRequestContextResolution::class);
        $lazy->method('canResolveEarly')->willReturn(true);
        $lazy->method('forRequest')->willThrowException(new LazyResponseException(new Response('', 401)));

        $this->expectException(LazyResponseException::class);
        $this->subscriber(null, $lazy)->onControllerArguments($this->arguments(Request::create('/api/x')));
    }

    #[Test]
    public function aKernelExceptionMarksTheMainRequestOnly(): void
    {
        $subscriber = $this->subscriber(null);
        $main       = Request::create('/api/x');
        $sub        = Request::create('/api/x');
        $subscriber->onException(new ExceptionEvent($this->createStub(HttpKernelInterface::class), $main, HttpKernelInterface::MAIN_REQUEST, new RuntimeException()));
        $subscriber->onException(new ExceptionEvent($this->createStub(HttpKernelInterface::class), $sub, HttpKernelInterface::SUB_REQUEST, new RuntimeException()));

        self::assertTrue($main->attributes->get(InboundRequestRecorder::EXCEPTION_ATTRIBUTE));
        self::assertFalse($sub->attributes->has(InboundRequestRecorder::EXCEPTION_ATTRIBUTE));
    }

    #[Test]
    public function theWarmerWritesTheMapAndDebugRebuildsItWhenARoutingFileChanges(): void
    {
        $dir      = sys_get_temp_dir() . '/http-log-map-' . bin2hex(random_bytes(6));
        $resource = $dir . '-routes.yaml';
        file_put_contents($resource, 'a');
        $routes = new RouteCollection();
        $routes->add('api_task', new Route('/api/tasks/{id}'));
        $routes->addResource(new FileResource($resource));
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::exactly(2))->method('getRouteCollection')->willReturn($routes);
        $matcher = InboundChannelMatcher::fromConfig(['api' => ['path_prefixes' => ['/api']]]);

        $warmer = new RouteTemplateCacheWarmer(new RouteTemplateMap($router, $matcher, new ConfigCacheFactory(true), $dir, true));
        self::assertTrue($warmer->isOptional());
        self::assertSame([], $warmer->warmUp($dir));
        self::assertFileExists($dir . '/' . RouteTemplateMap::RELATIVE_PATH);

        // a routing file changed since the warm-up: debug rebuilds the map (the router's own rule)
        touch($dir . '/' . RouteTemplateMap::RELATIVE_PATH, time() - 10);
        clearstatcache();
        $debug = new RouteTemplateMap($router, $matcher, new ConfigCacheFactory(true), $dir, true);
        self::assertSame('/api/tasks/{id}', $debug->template('api_task'));
        self::assertSame('{unrouted}', $debug->template(null));
        self::assertSame('{unrouted}', $debug->template('missing'));

        $prod = new RouteTemplateMap($router, $matcher, new ConfigCacheFactory(false), $dir);
        self::assertSame(['api_task' => '/api/tasks/{id}'], $prod->map());
        self::assertSame(['api_task' => '/api/tasks/{id}'], $prod->map());
        $prod->reset();
        self::assertSame('/api/tasks/{id}', $prod->template('api_task'));
    }
}
