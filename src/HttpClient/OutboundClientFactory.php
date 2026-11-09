<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\HttpClient;

use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds {@see LoggingHttpClient} instances: the decorators the compiler pass registers, and the clients
 * {@see HttpLogHttpClientWrapper} wraps.
 */
final readonly class OutboundClientFactory
{
    public function __construct(
        private OutboundCapturePolicy $policy,
        private HttpLogBuffer $buffer,
        private HttpLogContext $context,
        private ClockInterface $clock,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function create(HttpClientInterface $client, ?string $clientId = null, ?string $provider = null): LoggingHttpClient
    {
        return new LoggingHttpClient($client, $this->policy, $this->buffer, $this->context, $this->clock, $clientId, $provider, $this->logger);
    }
}
