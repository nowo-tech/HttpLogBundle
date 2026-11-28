<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\HttpClient;

/**
 * Where a provider keeps the machine code of a failed call (`outbound.providers.<name>.error_code`).
 */
final readonly class OutboundErrorCodeSpec
{
    /**
     * @param list<string> $paths JSON pointers ("/error/code") or dotted paths ("error.code"), read in order
     * @param string $prefix prepended to integer values only (an empty string = none)
     */
    public function __construct(
        public array $paths,
        public string $prefix = '',
    ) {
    }
}
