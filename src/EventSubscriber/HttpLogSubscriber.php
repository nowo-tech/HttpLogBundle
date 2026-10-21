<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\EventSubscriber;

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
use function random_int;

use const FNM_CASEFOLD;
use const PHP_INT_MAX;

/**
 * Captures HTTP requests and responses on kernel terminate.
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
        $request->attributes->set(self::ATTR_REQUEST_ID, $this->resolveRequestId($request));
    }

    public function onKernelTerminate(TerminateEvent $event): void
    {
        if (!$this->enabled || !$this->matchesEnvironment()) {
            return;
        }

        $request = $event->getRequest();

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
        // Segment-aware: "/admin/http-log" matches "/admin/http-log" and "/admin/http-log/1", not "/admin/http-logger".
        if ($this->webUiPathPrefix !== null && $this->webUiPathPrefix !== ''
            && ($path === $this->webUiPathPrefix || str_starts_with($path, $this->webUiPathPrefix . '/'))
        ) {
            return true;
        }

        foreach ($this->ignorePathPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRequestId(Request $request): string
    {
        $header = $request->headers->get('X-Request-Id');
        if (is_string($header) && $header !== '') {
            return substr($header, 0, 64);
        }

        return bin2hex(random_bytes(16));
    }
}
