<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Inbound;

use Nowo\HttpLogBundle\Inbound\ClientIpTruncator;
use Nowo\HttpLogBundle\Inbound\InboundChannel;
use Nowo\HttpLogBundle\Inbound\InboundChannelMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * HL-1 §3 matching table: segment-aware prefixes, case-insensitive route patterns, excludes, config order; Q-O8 IP
 * truncation.
 */
final class InboundChannelTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string|null, 2: string|null}> */
    public static function requests(): iterable
    {
        yield 'prefix itself' => ['/api', null, 'api'];
        yield 'below the prefix' => ['/api/tasks/1', 'api_task', 'api'];
        yield 'not a segment' => ['/apix', null, null];
        yield 'trailing slash only' => ['/api/', null, 'api'];
        yield 'excluded prefix' => ['/api/ingest/1', 'api_ingest', null];
        yield 'excluded route' => ['/api/health', 'api_health', null];
        yield 'route pattern, case-insensitive' => ['/x/mcp', 'MCP_endpoint', 'mcp'];
        yield 'route pattern without route' => ['/x/mcp', null, null];
        yield 'webhooks' => ['/hooks/stripe', 'hooks_stripe', 'webhooks'];
        yield 'web' => ['/hello', 'usage_hello', null];
        yield 'first channel wins' => ['/hooks/api', null, 'webhooks'];
    }

    #[Test]
    #[DataProvider('requests')]
    public function theMatcherFindsTheFirstChannel(string $path, ?string $route, ?string $expected): void
    {
        $matcher = InboundChannelMatcher::fromConfig([
            'webhooks' => ['path_prefixes' => ['/hooks']],
            'api'      => ['path_prefixes' => ['/api'], 'exclude_path_prefixes' => ['/api/ingest'], 'exclude_routes' => ['api_health']],
            'mcp'      => ['routes' => ['mcp_*']],
        ]);
        $request = Request::create($path);
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }

        self::assertSame($expected, $matcher->match($request)?->name);
    }

    #[Test]
    public function aRootPrefixMatchesEveryPathAndChannelsSelectTheirRoutes(): void
    {
        $all = new InboundChannel('all', ['/']);
        self::assertTrue($all->matches('/anything', null));

        $matcher = InboundChannelMatcher::fromConfig(['api' => ['path_prefixes' => ['/api'], 'routes' => ['legacy_*']]]);
        self::assertTrue($matcher->selectsRoute('api_task', '/api/tasks/{id}'));
        self::assertTrue($matcher->selectsRoute('legacy_feed', '/feed'));
        self::assertFalse($matcher->selectsRoute('usage_hello', '/hello'));
        self::assertSame(['api'], array_map(static fn (InboundChannel $c): string => $c->name, $matcher->channels()));

        $defaults = $matcher->channels()[0];
        self::assertSame([1.0, 400, InboundChannel::CLIENT_IP_TRUNCATED, true, true], [$defaults->samplingRate, $defaults->errorStatusMin, $defaults->clientIp, $defaults->user, $defaults->rollup]);
    }

    /** @return iterable<string, array{0: string|null, 1: string, 2: string|null}> */
    public static function addresses(): iterable
    {
        yield 'IPv4 truncated' => ['203.0.113.77', InboundChannel::CLIENT_IP_TRUNCATED, '203.0.113.0'];
        yield 'IPv6 truncated' => ['2001:db8:abcd:12:3456::1', InboundChannel::CLIENT_IP_TRUNCATED, '2001:db8:abcd::'];
        yield 'mapped IPv4 in IPv6' => ['::ffff:203.0.113.77', InboundChannel::CLIENT_IP_TRUNCATED, '::'];
        yield 'full' => ['203.0.113.77', InboundChannel::CLIENT_IP_FULL, '203.0.113.77'];
        yield 'none' => ['203.0.113.77', InboundChannel::CLIENT_IP_NONE, null];
        yield 'no address' => [null, InboundChannel::CLIENT_IP_FULL, null];
        yield 'not an address' => ['unknown', InboundChannel::CLIENT_IP_FULL, null];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function clientIpsAreKeptTruncatedOrDropped(?string $ip, string $mode, ?string $expected): void
    {
        self::assertSame($expected, ClientIpTruncator::apply($ip, $mode));
    }
}
