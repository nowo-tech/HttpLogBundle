<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Correlation;

use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use Nowo\CorrelationContract\Headers;
use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

use function is_string;
use function strcasecmp;

/**
 * Correlation ids of HttpLog rows (1.27, Beacon spec 235 FR-002 / FR-005). Stateless (worker safe): every value is read
 * from the host's provider, the request stack or the request at call time, never kept.
 *
 * - {@see fromProvider()}: the current id of the host's `CorrelationIdProviderInterface` (null without a provider,
 *   outside a unit of work, or when the provider fails).
 * - {@see current()}: the id an outbound call or a mail made right now belongs to: the provider's id, else the
 *   correlation id HttpLog kept for the main request being handled ({@see requestCorrelationId()}), else null.
 * - {@see upstream()}: the id an upstream system sent, accepted only from a trusted proxy and only when it parses.
 */
final readonly class CorrelationIds
{
    /**
     * @param list<string> $upstreamHeaders headers read in order by {@see upstream()}; `traceparent` gives its trace-id
     */
    public function __construct(
        private ?CorrelationIdProviderInterface $provider = null,
        private ?RequestStack $requestStack = null,
        private array $upstreamHeaders = Configuration::DEFAULT_UPSTREAM_HEADERS,
    ) {
    }

    public function fromProvider(): ?string
    {
        if (!$this->provider instanceof CorrelationIdProviderInterface) {
            return null;
        }

        try {
            return $this->provider->current()?->value;
        } catch (Throwable) {
            return null; // a failing host provider never breaks the request: HttpLog falls back to its own id
        }
    }

    public function current(): ?string
    {
        return $this->fromProvider() ?? $this->requestCorrelationId();
    }

    /**
     * The main request's correlation id kept by HttpLog: the provider's id it saw for that request, else the request's
     * own `request_id`; null outside a request (also at `kernel.terminate`, when the main request is already popped).
     */
    public function requestCorrelationId(): ?string
    {
        $attributes = $this->requestStack?->getMainRequest()?->attributes;
        if ($attributes === null) {
            return null;
        }

        foreach ([HttpLogSubscriber::ATTR_CORRELATION_ID, HttpLogSubscriber::ATTR_REQUEST_ID] as $name) {
            $id = $attributes->get($name);
            if (is_string($id) && CorrelationId::tryFromString($id) instanceof CorrelationId) {
                return $id;
            }
        }

        return null;
    }

    /**
     * The first configured header that holds a valid id, only when the request comes from a trusted proxy
     * (`Request::isFromTrustedProxy()`, framework.trusted_proxies). Anything else gives null; raw header values are
     * never logged.
     */
    public function upstream(Request $request): ?string
    {
        if (!$request->isFromTrustedProxy()) {
            return null;
        }

        foreach ($this->upstreamHeaders as $header) {
            $value = $request->headers->get($header);
            $id    = strcasecmp($header, Headers::TRACEPARENT) === 0
                ? CorrelationId::tryFromTraceparent($value)
                : CorrelationId::tryFromString($value);
            if ($id instanceof CorrelationId) {
                return $id->value;
            }
        }

        return null;
    }
}
