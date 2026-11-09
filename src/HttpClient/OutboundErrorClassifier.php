<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\HttpClient;

use Nowo\HttpLogBundle\Enum\OutboundErrorKind;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

use function preg_match;

/**
 * Error kind of a failed outbound call (HL-1 §5.4), from the exception class and substrings of the transport error
 * text matched here. The text is never stored: HttpClient messages contain the full URL (signed query strings).
 */
final class OutboundErrorClassifier
{
    /** @var array<string, OutboundErrorKind> pattern => kind, first match wins */
    private const PATTERNS = [
        '/timeout|timed out|max duration/i'                                                   => OutboundErrorKind::Timeout,
        '/resolve|getaddrinfo|dns|name or service not known|no address associated/i'          => OutboundErrorKind::Dns,
        '/ssl|tls|certificate|handshake/i'                                                    => OutboundErrorKind::Tls,
        '/connect|connection refused|network is unreachable|no route to host|reset by peer/i' => OutboundErrorKind::Connect,
        '/redirect/i'                                                                         => OutboundErrorKind::RedirectLimit,
        '/cancel/i'                                                                           => OutboundErrorKind::Cancelled,
    ];

    /**
     * @param string|null $message transport error text (matched, never kept)
     * @param Throwable|null $error the exception behind it, when known
     */
    public static function classify(?string $message, ?Throwable $error = null): OutboundErrorKind
    {
        if ($error instanceof TimeoutException || $error instanceof TimeoutExceptionInterface) {
            return OutboundErrorKind::Timeout;
        }
        if ($error instanceof Throwable && !$error instanceof TransportExceptionInterface) {
            return OutboundErrorKind::Exception;
        }

        foreach (self::PATTERNS as $pattern => $kind) {
            if ($message !== null && preg_match($pattern, $message) === 1) {
                return $kind;
            }
        }

        return OutboundErrorKind::Transport;
    }
}
