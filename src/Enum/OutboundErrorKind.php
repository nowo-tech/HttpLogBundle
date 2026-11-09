<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Enum;

/**
 * Error kind of an outbound or mailer row (`error_kind` column, HL-1 §5.4). Classified from the exception class and
 * the transport error text in code; the text itself is never stored.
 */
enum OutboundErrorKind: string
{
    case Timeout       = 'timeout';
    case Dns           = 'dns';
    case Connect       = 'connect';
    case Tls           = 'tls';
    case Transport     = 'transport';
    case RedirectLimit = 'redirect_limit';
    case Cancelled     = 'cancelled';
    case Http4xx       = 'http_4xx';
    case Http5xx       = 'http_5xx';
    case Exception     = 'exception';

    /** Error kind of a final HTTP status, null below 400. */
    public static function fromStatus(?int $status): ?self
    {
        return match (true) {
            $status === null || $status < 400 => null,
            $status < 500                     => self::Http4xx,
            default                           => self::Http5xx,
        };
    }
}
