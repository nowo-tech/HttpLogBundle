<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use DateTimeImmutable;
use InvalidArgumentException;

use const DATE_ATOM;

/**
 * One client IP address a user was logged from, with the first and last time it was seen for that user. Other bundles
 * (CookieConsent) use it to erase their own IP-keyed rows of that person within a time window, without querying the
 * HTTP log table.
 */
final readonly class HttpLogClientIpWindow
{
    /**
     * @throws InvalidArgumentException when the address is empty or the window ends before it starts
     */
    public function __construct(
        public string $ip,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
    ) {
        if ($ip === '') {
            throw new InvalidArgumentException('The client IP of a window must not be empty.');
        }
        if ($lastSeenAt < $firstSeenAt) {
            throw new InvalidArgumentException('A client IP window must not end before it starts.');
        }
    }

    /** @return array{client_ip: string, first_seen_at: string, last_seen_at: string} */
    public function toArray(): array
    {
        return [
            'client_ip'     => $this->ip,
            'first_seen_at' => $this->firstSeenAt->format(DATE_ATOM),
            'last_seen_at'  => $this->lastSeenAt->format(DATE_ATOM),
        ];
    }
}
