<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use function fnmatch;
use function str_starts_with;

use const FNM_CASEFOLD;

/**
 * One named inbound channel of `nowo_http_log.channels` (HL-1 §2): what it matches and what its rows keep.
 */
final readonly class InboundChannel
{
    public const CLIENT_IP_FULL      = 'full';
    public const CLIENT_IP_TRUNCATED = 'truncated';
    public const CLIENT_IP_NONE      = 'none';

    /**
     * @param list<string> $pathPrefixes segment-aware prefixes (no trailing "/", except "/" itself)
     * @param list<string> $routes fnmatch patterns on `_route`, case-insensitive
     * @param list<string> $excludePathPrefixes
     * @param list<string> $excludeRoutes
     * @param string $clientIp {@see CLIENT_IP_FULL}, {@see CLIENT_IP_TRUNCATED} or {@see CLIENT_IP_NONE}
     */
    public function __construct(
        public string $name,
        public array $pathPrefixes = [],
        public array $routes = [],
        public array $excludePathPrefixes = [],
        public array $excludeRoutes = [],
        public float $samplingRate = 1.0,
        public int $errorStatusMin = 400,
        public string $clientIp = self::CLIENT_IP_TRUNCATED,
        public bool $user = true,
        public bool $rollup = true,
    ) {
    }

    /**
     * @param array{path_prefixes?: list<string>, routes?: list<string>, exclude_path_prefixes?: list<string>, exclude_routes?: list<string>, sampling_rate?: float, error_status_min?: int, client_ip?: string, user?: bool, rollup?: bool} $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        return new self(
            $name,
            $config['path_prefixes'] ?? [],
            $config['routes'] ?? [],
            $config['exclude_path_prefixes'] ?? [],
            $config['exclude_routes'] ?? [],
            $config['sampling_rate'] ?? 1.0,
            $config['error_status_min'] ?? 400,
            $config['client_ip'] ?? self::CLIENT_IP_TRUNCATED,
            $config['user'] ?? true,
            $config['rollup'] ?? true,
        );
    }

    /** Included by a path prefix or a route pattern, and not excluded by either. */
    public function matches(string $path, ?string $route): bool
    {
        if (!self::prefixMatches($path, $this->pathPrefixes) && !$this->routeMatches($route, $this->routes)) {
            return false;
        }

        return !self::prefixMatches($path, $this->excludePathPrefixes) && !$this->routeMatches($route, $this->excludeRoutes);
    }

    /**
     * Whether a route (name and path template) belongs to this channel, for the route template map (HL-1 §6.2).
     */
    public function selectsRoute(string $name, string $path): bool
    {
        return self::prefixMatches($path, $this->pathPrefixes) || $this->routeMatches($name, $this->routes);
    }

    /**
     * "/api" matches "/api" and "/api/x", never "/apix"; "/" matches every path.
     *
     * @param list<string> $prefixes
     */
    public static function prefixMatches(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix === '/' || $path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $patterns */
    private function routeMatches(?string $route, array $patterns): bool
    {
        if ($route === null || $route === '') {
            return false;
        }
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $route, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
