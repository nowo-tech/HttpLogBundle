<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Nowo\CorrelationContract\CorrelationId;
use Nowo\CorrelationContract\CorrelationIdProviderInterface;

/**
 * A host correlation provider whose current id the test changes (null = outside any unit of work).
 */
final class MutableCorrelationIdProvider implements CorrelationIdProviderInterface
{
    public function __construct(public ?string $id = null)
    {
    }

    public function current(): ?CorrelationId
    {
        return $this->id === null ? null : new CorrelationId($this->id);
    }

    public function currentOrGenerate(): CorrelationId
    {
        return $this->current() ?? CorrelationId::generate();
    }
}
