<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use Nowo\HttpLogBundle\Usage\UsageFieldGuard;
use Symfony\Component\Config\ConfigCacheFactoryInterface;
use Symfony\Component\Config\ConfigCacheInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Service\ResetInterface;

use function is_array;
use function var_export;

/**
 * Route name => path template of the routes some named channel records (HL-1 §6.2), so a named-channel row stores
 * `/api/tasks/{id}`, never the raw path. Written to `%kernel.cache_dir%/nowo_http_log/route_templates.php` by
 * {@see RouteTemplateCacheWarmer} (or on first use) through the config cache: in debug it is rebuilt when a routing
 * resource changes, like the router's own cache. A route the map does not hold gives `{unrouted}`.
 */
final class RouteTemplateMap implements ResetInterface
{
    public const RELATIVE_PATH = 'nowo_http_log/route_templates.php';

    /** @var array<string, string>|null loaded map (not kept in debug, where the cache can change) */
    private ?array $map = null;

    public function __construct(
        private readonly RouterInterface $router,
        private readonly InboundChannelMatcher $matcher,
        private readonly ConfigCacheFactoryInterface $cacheFactory,
        private readonly string $cacheDir,
        private readonly bool $debug = false,
    ) {
    }

    /** Path template of a route, `{unrouted}` without a route or for a route outside the map. */
    public function template(?string $route): string
    {
        if ($route === null || $route === '') {
            return UsageFieldGuard::UNROUTED_PATH;
        }

        return UsageFieldGuard::path($this->map()[$route] ?? UsageFieldGuard::UNROUTED_PATH);
    }

    /** @return array<string, string> */
    public function map(): array
    {
        if ($this->map !== null) {
            return $this->map;
        }

        $map = $this->load($this->cacheDir);
        if (!$this->debug) {
            // @igor-ignore - read-only cache of a compiled file, cleared by kernel.reset; never request data
            $this->map = $map;
        }

        return $map;
    }

    /**
     * Builds (when missing or stale) and reads the map file of `$cacheDir`.
     *
     * @return array<string, string>
     */
    public function load(string $cacheDir): array
    {
        $cache = $this->cacheFactory->cache($cacheDir . '/' . self::RELATIVE_PATH, function (ConfigCacheInterface $cache): void {
            $collection = $this->router->getRouteCollection();
            $map        = [];
            foreach ($collection->all() as $name => $route) {
                if ($this->matcher->selectsRoute($name, $route->getPath())) {
                    $map[$name] = $route->getPath();
                }
            }
            $cache->write('<?php return ' . var_export($map, true) . ";\n", $collection->getResources());
        });

        /** @var mixed $map */
        $map = require $cache->getPath();

        return is_array($map) ? $map : [];
    }

    public function reset(): void
    {
        $this->map = null;
    }
}
