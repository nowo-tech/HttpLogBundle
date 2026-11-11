<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Nowo\HttpLogBundle\Inbound\HttpLogRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpRequestContext;
use Nowo\HttpLogBundle\Inbound\HttpRequestContextResolverInterface;

/** Test double of the framework-free port: records every context and channel it is given. */
final class RecordingContextResolver implements HttpRequestContextResolverInterface
{
    /** @var list<HttpRequestContext> */
    public array $contexts = [];

    /** @var list<string> */
    public array $channels = [];

    public function __construct(private readonly ?HttpLogRequestContext $answer)
    {
    }

    public function resolveContext(HttpRequestContext $request, string $channel): ?HttpLogRequestContext
    {
        $this->contexts[] = $request;
        $this->channels[] = $channel;

        return $this->answer;
    }
}
