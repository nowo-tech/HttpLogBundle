<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\EventSubscriber;

use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Nowo\HttpLogBundle\Inbound\HttpLogRequestContextResolution;
use Nowo\HttpLogBundle\Inbound\InboundChannelMatcher;
use Nowo\HttpLogBundle\Inbound\InboundRequestRecorder;
use Nowo\HttpLogBundle\Inbound\RouteTemplateMap;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Tests\Support\UsageTestRig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\ConfigCacheFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

use function array_column;
use function microtime;
use function sys_get_temp_dir;

/**
 * HL-1 §3 matching order at terminate: admin UI → named channels → web (ignores, the `rollup.web` count, sampling).
 */
final class HttpLogSubscriberChannelsTest extends TestCase
{
    private UsageTestRig $rig;

    protected function setUp(): void
    {
        $this->rig = new UsageTestRig();
    }

    private function subscriber(HttpLogRecorder $web, float $webSampling = 1.0, bool $rollupWeb = false): HttpLogSubscriber
    {
        $matcher = InboundChannelMatcher::fromConfig(['api' => ['path_prefixes' => ['/api']]]);
        $map     = new RouteTemplateMap($this->createStub(RouterInterface::class), $matcher, new ConfigCacheFactory(false), sys_get_temp_dir());
        $inbound = new InboundRequestRecorder($matcher, $map, new HttpLogRequestContextResolution(), $this->rig->buffer, $this->rig->clock, null, true, $rollupWeb);

        return new HttpLogSubscriber($web, true, [], $webSampling, false, [], ['/api', '/assets'], 'test', false, '/admin/http-log', $inbound);
    }

    private function terminate(HttpLogSubscriber $subscriber, string $path, bool $started = true): void
    {
        $request = Request::create($path);
        if ($started) {
            $request->attributes->set(HttpLogSubscriber::ATTR_START_TIME, microtime(true));
            $request->attributes->set(HttpLogSubscriber::ATTR_REQUEST_ID, 'abc');
        }
        $subscriber->onKernelTerminate(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response('ok')));
    }

    /** @return list<string> */
    private function channels(): array
    {
        $this->rig->buffer->flush();

        return array_column(array_column($this->rig->messages, 'payload'), 'channel');
    }

    #[Test]
    public function aNamedChannelRecordsBeforeTheWebIgnoresAndTheAdminUiIsNeverRecorded(): void
    {
        $web = $this->createMock(HttpLogRecorder::class);
        $web->expects(self::once())->method('record')->with(self::anything(), self::anything(), self::anything(), 'abc');
        $subscriber = $this->subscriber($web);

        $this->terminate($subscriber, '/api/tasks/1');
        $this->terminate($subscriber, '/api/tasks/2', false); // never started: nothing
        $this->terminate($subscriber, '/admin/http-log/3');
        $this->terminate($subscriber, '/assets/app.css');
        $this->terminate($subscriber, '/hello', false);
        $this->terminate($subscriber, '/hello');

        self::assertSame(['api'], $this->channels());
    }

    #[Test]
    public function rollupWebCountsEveryWebRequestTheIgnoresKeepEvenUnsampled(): void
    {
        $web = $this->createMock(HttpLogRecorder::class);
        $web->expects(self::never())->method('record');
        $subscriber = $this->subscriber($web, 0.0, true);

        $this->terminate($subscriber, '/hello');
        $this->terminate($subscriber, '/assets/app.css');

        self::assertSame(['web'], $this->channels());
    }
}
