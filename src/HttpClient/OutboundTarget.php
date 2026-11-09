<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\HttpClient;

/**
 * What an outbound row of one call will store about its target: provider, scheme, host and path template.
 */
final readonly class OutboundTarget
{
    public function __construct(
        public string $provider,
        public ?string $scheme,
        public string $host,
        public string $path,
        public bool $perCall = true,
    ) {
    }
}
