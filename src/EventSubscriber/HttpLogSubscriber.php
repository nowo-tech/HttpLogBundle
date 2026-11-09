<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\EventSubscriber;

use Nowo\HttpLogBundle\Inbound\InboundChannel;
use Nowo\HttpLogBundle\Inbound\InboundRequestRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

use function bin2hex;
use function fnmatch;
use function in_array;
use function is_float;
use function is_string;
use function microtime;
use function random_bytes;
use function random_int;

use const FNM_CASEFOLD;
use const PHP_INT_MAX;

/**
 * Captures HTTP requests and responses on kernel terminate.
 *
 * With named channels (HL-1 §3) a request is matched against them first, after the admin UI prefix: a request a
 * named channel records never reaches the web channel's ignores or sampling. Without named channels and without
 * `rollup.web`, the 1.14 flow runs unchanged.
 */
final readonly class HttpLogSubscriber implements EventSubscriberInterface
{
    public const ATTR_START_TIME = '_nowo_http_log_start_time';
    public const ATTR_REQUEST_ID = '_nowo_http_log_request_id';

    /**
     * @param list<string> $environments
     * @param list<string> $ignoreRoutes
     * @param list<string> $ignorePathPrefixes
     * @param string|null $webUiPathPrefix Admin UI route prefix (web_ui.path_prefix) when the web UI is enabled; always ignored, segment-aware
     */
    public function __construct(
        private HttpLogRecorder $recorder,
        private bool $enabled,
        private array $environments,
        private float $samplingRate,
        private bool $trackSubRequests,
        private array $ignoreRoutes,
        private array $ignorePathPrefixes,
        private string $kernelEnvironment,
        private bool $ignoreUnrouted = false,
        private ?string $webUiPathPrefix = null,
        // HL-1: set while a named channel or rollup.web is configured
        private ?InboundRequestRecorder $inbound = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST   => ['onKernelRequest', 1024],
            KernelEvents::TERMINATE => ['onKernelTerminate', -1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() && !$this->trackSubRequests) {
            return;
        }

        $request = $event->getRequest();
        $request->attributes->set(self::ATTR_START_TIME, microtime(true));
        $request->attributes->set(self::ATTR_REQUEST_ID, $this->generateRequestId());
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->enabled || !$this->matchesEnvironment()) {
            return;
        }

        $request = $event->getRequest();

        if ($this->inbound instanceof InboundRequestRecorder) {
            $this->terminateWithChannels($event, $this->inbound);

            return;
        }

        if (!$this->passesSampling()) {
            return;
        }

        if ($this->isIgnoredRoute($request) || $this->isIgnoredPath($request) || $this->isIgnoredUnrouted($request)) {
            return;
        }

        $startTime = $request->attributes->get(self::ATTR_START_TIME);
        if (!is_float($startTime)) {
            return;
        }

        $durationMs = (microtime(true) - $startTime) * 1000.0;
        $requestId  = $request->attributes->get(self::ATTR_REQUEST_ID);
        $requestId  = is_string($requestId) ? $requestId : null;

        $this->recorder->record($request, $event->getResponse(), $durationMs, $requestId);
    }

    /**
     * Admin UI → named channels → web channel (ignores, then the `rollup.web` count, then sampling).
     */
    private function terminateWithChannels(TerminateEvent $event, InboundRequestRecorder $inbound): void
    {
        $request = $event->getRequest();
        if ($this->isAdminPath($request->getPathInfo())) {
            return;
        }

        $startTime = $request->attributes->get(self::ATTR_START_TIME);
        $channel   = $inbound->match($request);
        if ($channel instanceof InboundChannel) {
            if (is_float($startTime)) {
                $inbound->record($channel, $request, $event->getResponse(), (microtime(true) - $startTime) * 1000.0);
            }

            return;
        }

        if ($this->isIgnoredRoute($request) || $this->isIgnoredPath($request) || $this->isIgnoredUnrouted($request) || !is_float($startTime)) {
            return;
        }

        $durationMs = (microtime(true) - $startTime) * 1000.0;
        if ($inbound->countsWeb()) {
            $inbound->countWeb($request, $event->getResponse(), $durationMs);
        }
        if (!$this->passesSampling()) {
            return;
        }

        $requestId = $request->attributes->get(self::ATTR_REQUEST_ID);
        $this->recorder->record($request, $event->getResponse(), $durationMs, is_string($requestId) ? $requestId : null);
    }

    private function matchesEnvironment(): bool
    {
        if ($this->environments === []) {
            return true;
        }

        return in_array($this->kernelEnvironment, $this->environments, true);
    }

    private function passesSampling(): bool
    {
        if ($this->samplingRate >= 1.0) {
            return true;
        }

        if ($this->samplingRate <= 0.0) {
            return false;
        }

        return random_int(0, PHP_INT_MAX) / PHP_INT_MAX <= $this->samplingRate;
    }

    private function isIgnoredUnrouted(Request $request): bool
    {
        if (!$this->ignoreUnrouted) {
            return false;
        }

        $route = $request->attributes->get('_route');

        return !is_string($route) || $route === '';
    }

    private function isIgnoredRoute(Request $request): bool
    {
        $route = $request->attributes->get('_route');
        if (!is_string($route) || $route === '') {
            return false;
        }

        foreach ($this->ignoreRoutes as $pattern) {
            if (fnmatch($pattern, $route, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    private function isIgnoredPath(Request $request): bool
    {
        $path = $request->getPathInfo();

        // The admin UI must never record its own requests, whatever ignore_path_prefixes says.
        if ($this->isAdminPath($path)) {
            return true;
        }

        foreach ($this->ignorePathPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Segment-aware: "/admin/http-log" matches "/admin/http-log" and "/admin/http-log/1", not "/admin/http-logger". */
    private function isAdminPath(string $path): bool
    {
        return $this->webUiPathPrefix !== null && $this->webUiPathPrefix !== ''
            && ($path === $this->webUiPathPrefix || str_starts_with($path, $this->webUiPathPrefix . '/'));
    }

    /**
     * The stored request id is the dedupe key of the log row (unique column, async retry guard), so it is
     * always generated here and never taken from the client: a client-supplied X-Request-Id repeated on
     * many requests would otherwise collapse them into one row. The client's header, when sent, stays in
     * the captured request headers for correlation only.
     */
    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
