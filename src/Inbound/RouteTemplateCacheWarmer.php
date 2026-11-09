<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Writes the route template map of the named channels at `cache:warmup` (HL-1 §6.2). Optional: the map is built on
 * first use otherwise.
 */
final readonly class RouteTemplateCacheWarmer implements CacheWarmerInterface
{
    public function __construct(private RouteTemplateMap $map)
    {
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->map->load($cacheDir);

        return [];
    }
}
