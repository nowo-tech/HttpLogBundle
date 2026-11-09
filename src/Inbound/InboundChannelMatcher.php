<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * Finds the named channel of an inbound request (HL-1 §3): channels in config order, include lists minus exclude
 * lists; the first that matches records the request. The admin UI prefix is ignored before (existing rule), the web
 * channel's ignores and sampling after: a request matched here never reaches them.
 */
final readonly class InboundChannelMatcher
{
    /** @param list<InboundChannel> $channels */
    public function __construct(private array $channels)
    {
    }

    /**
     * @param array<string, array<string, mixed>> $config `nowo_http_log.channels`
     */
    public static function fromConfig(array $config): self
    {
        $channels = [];
        foreach ($config as $name => $channel) {
            /* @var array{path_prefixes?: list<string>, routes?: list<string>, exclude_path_prefixes?: list<string>, exclude_routes?: list<string>, sampling_rate?: float, error_status_min?: int, client_ip?: string, user?: bool, rollup?: bool} $channel */
            $channels[] = InboundChannel::fromConfig($name, $channel);
        }

        return new self($channels);
    }

    public function match(Request $request): ?InboundChannel
    {
        $route = $request->attributes->get('_route');
        $route = is_string($route) ? $route : null;
        $path  = $request->getPathInfo();
        foreach ($this->channels as $channel) {
            if ($channel->matches($path, $route)) {
                return $channel;
            }
        }

        return null;
    }

    /** True when some channel would record requests of this route (template map, HL-1 §6.2). */
    public function selectsRoute(string $name, string $path): bool
    {
        foreach ($this->channels as $channel) {
            if ($channel->selectsRoute($name, $path)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<InboundChannel> */
    public function channels(): array
    {
        return $this->channels;
    }
}
