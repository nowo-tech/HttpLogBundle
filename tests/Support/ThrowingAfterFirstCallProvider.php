<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;
use RuntimeException;

/**
 * A host correlation provider that answers once, then throws on every call (a provider broken mid-request).
 */
final class ThrowingAfterFirstCallProvider implements CorrelationIdProviderInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $first)
    {
    }

    public function current(): ?CorrelationId
    {
        if (++$this->calls > 1) {
            throw new RuntimeException('provider failure');
        }

        return new CorrelationId($this->first);
    }

    public function currentOrGenerate(): CorrelationId
    {
        return $this->current() ?? CorrelationId::generate();
    }
}
