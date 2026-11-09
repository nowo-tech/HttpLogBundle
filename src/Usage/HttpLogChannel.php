<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

/**
 * Channel names of the `channel` column (HL-1 §3). Web rows keep `channel` NULL (1.14 behaviour); `web` is the name
 * the purge scopes, filters and the channel access checker use for them.
 */
final class HttpLogChannel
{
    public const WEB      = 'web';
    public const OUTBOUND = 'outbound';
    public const MAILER   = 'mailer';
}
