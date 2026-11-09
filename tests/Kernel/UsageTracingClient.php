<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Kernel;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Stand-in for a tracing decorator (TraceableHttpClient, BeaconBundle instrumentation) at priority 100. */
final class UsageTracingClient implements HttpClientInterface
{
    use DecoratorTrait;

    public int $calls = 0;

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        ++$this->calls;

        return $this->client->request($method, $url, $options);
    }
}
