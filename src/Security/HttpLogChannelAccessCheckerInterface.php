<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Security;

/**
 * Optional capability of the configured access checker (HL-1 §11.2), checked with `instanceof`: when the checker
 * implements it, the admin list, entry page, export and purge leave out the channels it denies (a denied entry gives
 * the same 404 as a missing one). Channels: `web` (rows without a channel), `outbound`, `mailer`.
 */
interface HttpLogChannelAccessCheckerInterface
{
    public function canViewChannel(?object $user, string $channel): bool;
}
