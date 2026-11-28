<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use Nowo\CorrelationContract\CorrelationId;
use Nowo\HttpLogBundle\Enum\OutboundErrorKind;

use function count;
use function explode;
use function filter_var;
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
use function substr_count;
use function trim;

use const FILTER_VALIDATE_IP;
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

    /** One provider error code (`outbound.providers.<name>.error_code`). */
    private const ERROR_CODE_PART = '/^[A-Za-z0-9_.:-]{1,64}\z/';

    /** A UUID, or a value that starts like a well-known credential (JWT, Stripe, GitHub, Slack, AWS, Google keys). */
    private const ERROR_CODE_OPAQUE = '/^(?:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z|(?:eyJ|sk_|rk_|pk_live|pk_test|ghp_|gho_|ghs_|ghu_|ghr_|xox|AKIA|AIza))/i';

    /** Stored in place of a provider code that fails {@see self::errorCodePart()}. */
    public const UNKNOWN_ERROR_CODE = 'unknown';

    /** Longest stored `error_code` (bytes). */
    public const MAX_ERROR_CODE_LENGTH = 128;

    private const EVENT_ID = '/^[0-9a-f]{32}\z/';

    /** Path stored when the templated path is not valid. */
    public const COLLAPSED_PATH = '/{…}';

    /** Path of a named-channel row whose route has no template (HL-1 §6.2): never the raw path. */
    public const UNROUTED_PATH = '{unrouted}';

    /** Route names: printable ASCII, the `route_name` column is 255 characters. */
    private const ROUTE_NAME = '/^[\x21-\x7E]{1,255}\z/';

    public const MAX_PATH_LENGTH = 512;

    /** Method stored when the request method is not a valid token. */
    public const OTHER_METHOD = 'OTHER';

    public static function token(mixed $value, int $maxLength = 64): ?string
    {
        return is_string($value) && preg_match(sprintf(self::TOKEN, $maxLength), $value) === 1 ? $value : null;
    }

    /** Provider key: a token, plus "+" so an unmapped mail transport scheme (`resend+api`) can be the provider. */
    public static function provider(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._:@+-]{1,64}\z/', $value) === 1 ? $value : null;
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
        if ($value === self::UNROUTED_PATH) {
            return $value;
        }

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

    /**
     * One provider error code: `A-Z a-z 0-9 _ . : -`, 1..64 characters, and not shaped like a JWT, a UUID or a long hex
     * string (an identifier or a credential is not a code). Anything else is `unknown`; the original is never kept.
     */
    public static function errorCodePart(mixed $value): string
    {
        if (is_string($value) && self::isCode($value)) {
            return $value;
        }

        return self::UNKNOWN_ERROR_CODE;
    }

    /**
     * The shape of a machine code: the charset, and none of the shapes of an identifier, a person or a credential. A
     * guard, not a proof: hosts point `paths` at fields the provider documents as codes.
     */
    private static function isCode(string $value): bool
    {
        if (preg_match(self::ERROR_CODE_PART, $value) !== 1
            || preg_match(self::ERROR_CODE_OPAQUE, $value) === 1
            || preg_match('/[0-9]{10}|[0-9a-f]{16}/i', $value) === 1 // account / card / phone numbers, hex ids
            || substr_count($value, '.') >= 2 // host names, JWT-like tokens
            || filter_var($value, FILTER_VALIDATE_IP) !== false
        ) {
            return false;
        }
        // a long run that mixes upper case, lower case and digits is a key or a token, not a word
        if (preg_match_all('/[A-Za-z0-9]{20,}/', $value, $runs) > 0) {
            foreach ($runs[0] as $run) {
                if (preg_match('/[a-z]/', $run) === 1 && preg_match('/[A-Z]/', $run) === 1 && preg_match('/[0-9]/', $run) === 1) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The stored error code: 1..8 parts of {@see self::errorCodePart()} joined by ",", at most 128 bytes. Anything
     * else is NULL (a payload read back from a transport is validated again, never repaired).
     */
    public static function errorCode(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || strlen($value) > self::MAX_ERROR_CODE_LENGTH) {
            return null;
        }
        $parts = explode(',', $value);
        if (count($parts) > 8) {
            return null;
        }
        foreach ($parts as $part) {
            if (!self::isCode($part)) {
                return null;
            }
        }

        return $value;
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

    public static function routeName(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::ROUTE_NAME, $value) === 1 ? $value : null;
    }

    /** An IPv4 or IPv6 address (as stored by the web channel: the `client_ip` column is 45 characters). */
    public static function clientIp(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 45 && filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    /** User identifier: valid UTF-8 without control characters, at most 255 characters (the web channel stores it as is). */
    public static function userIdentifier(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && mb_strlen($value) <= 255 && preg_match('/^[^\x00-\x1F\x7F]+\z/u', $value) === 1 ? $value : null;
    }

    /** 1.27: a correlation id ({@see CorrelationId::PATTERN}: 1 to 64 of A-Z a-z 0-9 . _ : -), else null. */
    public static function correlationId(mixed $value): ?string
    {
        return is_string($value) ? CorrelationId::tryFromString($value)?->value : null;
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
