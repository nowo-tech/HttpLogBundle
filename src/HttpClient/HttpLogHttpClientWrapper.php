<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\HttpClient;

use Nowo\HttpLogBundle\Usage\UsageFieldGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Public service for clients built in code, which the decorating pass cannot see (`HttpClient::create()`, an AsyncAws
 * `S3Client(['httpClient' => …])`, a WebPush or Mercure client), HL-1 §5.2:
 *
 * ```php
 * $s3 = new S3Client($config, httpClient: $wrapper->wrap(HttpClient::create(), 's3'));
 * ```
 *
 * With outbound capture disabled it returns the client unchanged; an already wrapped (or decorated) client is not
 * wrapped twice. An invalid provider key is ignored (the provider map decides).
 */
final readonly class HttpLogHttpClientWrapper
{
    public function __construct(private ?OutboundClientFactory $factory = null)
    {
    }

    public function wrap(HttpClientInterface $client, string $provider, ?string $clientId = null): HttpClientInterface
    {
        if (!$this->factory instanceof OutboundClientFactory || $client instanceof LoggingHttpClient) {
            return $client;
        }

        return $this->factory->create($client, $clientId, UsageFieldGuard::token($provider));
    }
}
