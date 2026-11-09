<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use function filter_var;
use function inet_ntop;
use function inet_pton;
use function str_repeat;
use function strlen;
use function substr;

use const FILTER_VALIDATE_IP;

/**
 * Client IP of a named-channel row (Q-O8): kept, truncated to its network (IPv4 /24, IPv6 /48) or dropped.
 */
final class ClientIpTruncator
{
    public static function apply(?string $ip, string $mode): ?string
    {
        if ($ip === null || $mode === InboundChannel::CLIENT_IP_NONE || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if ($mode === InboundChannel::CLIENT_IP_FULL) {
            return $ip;
        }

        $packed = (string) inet_pton($ip);
        // IPv4: 3 of 4 bytes (/24); IPv6: 6 of 16 bytes (/48)
        $keep = strlen($packed) === 4 ? 3 : 6;

        return (string) inet_ntop(substr($packed, 0, $keep) . str_repeat("\0", strlen($packed) - $keep));
    }
}
