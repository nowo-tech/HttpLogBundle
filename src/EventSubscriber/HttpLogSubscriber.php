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
final class HttpLogSubscriber implements EventSubscriberInterface
{
    public const ATTR_START_TIME = '_nowo_http_log_start_time';
    public const ATTR_REQUEST_ID = '_nowo_http_log_request_id';

    /**
     * @param list<string> $environments
     * @param list<string> $ignoreRoutes
     * @param list<string> $ignorePathPrefixes
     */
    public function __construct(
        private readonly HttpLogRecorder $recorder,
        private readonly bool $enabled,
        private readonly array $environments,
        private readonly float $samplingRate,
        private readonly bool $trackSubRequests,
        private readonly array $ignoreRoutes,
        private readonly array $ignorePathPrefixes,
        private readonly string $kernelEnvironment,
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

        if ($this->isIgnoredRoute($request) || $this->isIgnoredPath($request)) {
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
