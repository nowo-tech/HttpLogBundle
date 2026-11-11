<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use InvalidArgumentException;

use function mb_strlen;
use function sprintf;
use function str_contains;

/**
 * Whose HTTP log entries are erased or exported: an authenticated user identifier or a client IP address, compared
 * exactly as logged (no trimming, no case folding, no IP normalisation).
 *
 * A client IP may be shared (NAT, carrier-grade NAT, offices): erasing or exporting by IP reaches every person behind
 * it. The UserKit bridge only uses user identifiers.
 */
final readonly class HttpLogPersonalDataSubject
{
    /** Entries whose `user_identifier` column holds the value. */
    public const TYPE_USER_IDENTIFIER = 'user_identifier';

    /** Entries whose `client_ip` column holds the value. */
    public const TYPE_CLIENT_IP = 'client_ip';

    /** Length of the `user_identifier` column. */
    public const MAX_USER_IDENTIFIER_LENGTH = 255;

    /** Length of the `client_ip` column. */
    public const MAX_CLIENT_IP_LENGTH = 45;

    /**
     * @param self::TYPE_* $type
     * @param non-empty-string $value
     */
    private function __construct(
        public string $type,
        public string $value,
    ) {
    }

    /**
     * @param string $userIdentifier Value of `UserInterface::getUserIdentifier()` when the request was logged
     *
     * @throws InvalidArgumentException when the value is empty, contains a NUL byte or is longer than 255 characters
     */
    public static function fromUserIdentifier(string $userIdentifier): self
    {
        return new self(self::TYPE_USER_IDENTIFIER, self::checked('user identifier', $userIdentifier, self::MAX_USER_IDENTIFIER_LENGTH));
    }

    /**
     * @param string $clientIp IPv4 or IPv6 address as logged (after any trusted-proxy resolution or truncation)
     *
     * @throws InvalidArgumentException when the value is empty, contains a NUL byte or is longer than 45 characters
     */
    public static function fromClientIp(string $clientIp): self
    {
        return new self(self::TYPE_CLIENT_IP, self::checked('client IP', $clientIp, self::MAX_CLIENT_IP_LENGTH));
    }

    public function isClientIp(): bool
    {
        return $this->type === self::TYPE_CLIENT_IP;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->value === $other->value;
    }

    /** @return array{type: self::TYPE_*, value: non-empty-string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'value' => $this->value];
    }

    /**
     * Rejects values the database could not match exactly: empty, with a NUL byte (PDO cuts bound values at NUL) or
     * longer than the column.
     *
     * @return non-empty-string
     */
    private static function checked(string $what, string $value, int $max): string
    {
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The %s must not be empty.', $what));
        }
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException(sprintf('The %s must not contain a NUL byte.', $what));
        }
        if (mb_strlen($value, 'UTF-8') > $max) {
            throw new InvalidArgumentException(sprintf('The %s must not be longer than %d characters.', $what, $max));
        }

        return $value;
    }
}
