<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use Nowo\HttpLogBundle\Enum\OutboundErrorKind;

use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function strlen;
use function strtolower;
use function strtoupper;
use function trim;

use const PHP_INT_MAX;

/**
 * Charset and length rules of every column the usage channels store (HL-1 §4.1). A value that fails its rule is
 * replaced by NULL (or the documented placeholder for a NOT NULL column), never truncated: a cut secret cannot leak a
 * prefix.
 */
final class UsageFieldGuard
{
    /** Opaque keys: tenant, project, provider, credential kind and id. */
    private const TOKEN = '/^[A-Za-z0-9._:@-]{1,%d}\z/';

    private const CHANNEL = '/^[a-z][a-z0-9_]{0,15}\z/';

    private const METHOD = '/^[A-Z][A-Z0-9_-]{0,15}\z/';

    private const SCHEME = '/^[a-z][a-z0-9+.-]{0,15}\z/';

    /** Lower-case host name or bracketed IPv6 address, optional port. */
    private const HOST = '/^(?:\[[0-9a-f:.]{2,45}\]|[a-z0-9_](?:[a-z0-9_.-]{0,252}))(?::[0-9]{1,5})?\z/';

    /** Path template: printable ASCII and the `…` of the collapsed rest, starting with "/". */
    private const PATH = '/^\/(?:[\x21-\x7E]|…)*\z/u';

    private const CONTENT_TYPE = '/^[a-z0-9][a-z0-9!#$&^_.+-]{0,63}\/[a-z0-9][a-z0-9!#$&^_.+-]{0,62}\z/';

    private const MESSAGE_ID = '/^[A-Za-z0-9._:-]{1,64}\z/';

    private const EXTERNAL_ID = '/^[\x21-\x7E]{1,255}\z/';

    private const EVENT_ID = '/^[0-9a-f]{32}\z/';

    /** Path stored when the templated path is not valid. */
    public const COLLAPSED_PATH = '/{…}';

    public const MAX_PATH_LENGTH = 512;

    /** Method stored when the request method is not a valid token. */
    public const OTHER_METHOD = 'OTHER';

    public static function token(mixed $value, int $maxLength = 64): ?string
    {
        return is_string($value) && preg_match(sprintf(self::TOKEN, $maxLength), $value) === 1 ? $value : null;
    }

    public static function channel(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::CHANNEL, $value) === 1 ? $value : null;
    }

    public static function method(mixed $value): string
    {
        if (!is_string($value)) {
            return self::OTHER_METHOD;
        }
        $value = strtoupper($value);

        return preg_match(self::METHOD, $value) === 1 ? $value : self::OTHER_METHOD;
    }

    public static function scheme(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower($value);

        return preg_match(self::SCHEME, $value) === 1 ? $value : null;
    }

    public static function host(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 255 && preg_match(self::HOST, $value) === 1 ? $value : null;
    }

    public static function path(mixed $value): string
    {
        return is_string($value) && mb_strlen($value) <= self::MAX_PATH_LENGTH && preg_match(self::PATH, $value) === 1 ? $value : self::COLLAPSED_PATH;
    }

    /** MIME type without parameters, lower-cased. */
    public static function contentType(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $semicolon = strpos($value, ';');
        $value     = strtolower(trim($semicolon === false ? $value : substr($value, 0, $semicolon)));

        return strlen($value) <= 128 && preg_match(self::CONTENT_TYPE, $value) === 1 ? $value : null;
    }

    public static function errorKind(mixed $value): ?string
    {
        if ($value instanceof OutboundErrorKind) {
            return $value->value;
        }

        return is_string($value) ? OutboundErrorKind::tryFrom($value)?->value : null;
    }

    public static function statusCode(mixed $value): ?int
    {
        $value = self::integer($value);

        return $value !== null && $value >= 100 && $value <= 999 ? $value : null;
    }

    public static function nonNegativeInt(mixed $value, int $max = PHP_INT_MAX): ?int
    {
        $value = self::integer($value);

        return $value !== null && $value >= 0 && $value <= $max ? $value : null;
    }

    public static function duration(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $value = (float) $value;

        return is_finite($value) && $value >= 0.0 ? $value : null;
    }

    public static function messageId(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::MESSAGE_ID, $value) === 1 ? $value : null;
    }

    /** Message-ID of a sent mail without its angle brackets: printable ASCII, at most 255 characters. */
    public static function externalMessageId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (str_starts_with($value, '<') && str_ends_with($value, '>')) {
            $value = substr($value, 1, -1);
        }

        return preg_match(self::EXTERNAL_ID, $value) === 1 ? $value : null;
    }

    public static function eventId(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::EVENT_ID, $value) === 1 ? $value : null;
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && is_finite($value) && $value === (float) (int) $value) {
            return (int) $value;
        }
        if (is_string($value) && is_numeric($value) && preg_match('/^-?[0-9]{1,18}\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
