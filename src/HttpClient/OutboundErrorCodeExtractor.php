<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\HttpClient;

use JsonException;
use Nowo\HttpLogBundle\Usage\UsageFieldGuard;

use function array_is_list;
use function array_slice;
use function count;
use function explode;
use function is_array;
use function is_int;
use function json_decode;
use function ltrim;
use function str_starts_with;
use function strlen;

use const JSON_THROW_ON_ERROR;

/**
 * Reads the machine error code of a failed outbound call from its JSON response body (status >= 400 only).
 *
 * Only values read at the configured paths are kept, and each one must be a short allowlisted token
 * ({@see UsageFieldGuard::errorCodePart()}): `error_description`, `message`, trace ids, e-mail addresses and tokens have
 * no path to travel in, and a value of any other shape is stored as `unknown`. The body itself is never stored.
 * Stateless: the body belongs to the call that read it.
 */
final class OutboundErrorCodeExtractor
{
    /** Largest response body (bytes) read for a code; a longer one yields no code. */
    public const MAX_BODY_BYTES = 16384;

    /** Values kept from one array (e.g. a list of integer codes). */
    public const MAX_VALUES_PER_PATH = 5;

    private const MAX_DEPTH = 16;

    /**
     * @return string|null the code (values joined by ","), or null when the body is not JSON, is too large, or none of
     *                     the paths is present
     */
    public static function extract(string $body, OutboundErrorCodeSpec $spec): ?string
    {
        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            return null;
        }

        try {
            $data = json_decode($body, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $parts = [];
        foreach ($spec->paths as $path) {
            $found = self::lookup($data, $path);
            if ($found === null) {
                continue;
            }
            foreach (is_array($found) && array_is_list($found) ? array_slice($found, 0, self::MAX_VALUES_PER_PATH) : [$found] as $value) {
                $parts[] = is_int($value) && $spec->prefix !== '' ? UsageFieldGuard::errorCodePart($spec->prefix . $value) : UsageFieldGuard::errorCodePart(is_int($value) ? (string) $value : $value);
            }
        }

        $kept = '';
        foreach ($parts as $part) {
            $next = $kept === '' ? $part : $kept . ',' . $part;
            if (strlen($next) > UsageFieldGuard::MAX_ERROR_CODE_LENGTH || count(explode(',', $next)) > 8) {
                break;
            }
            $kept = $next;
        }

        return $kept === '' ? null : $kept;
    }

    /** @param array<mixed> $data */
    private static function lookup(array $data, string $path): mixed
    {
        $segments = explode(str_starts_with($path, '/') ? '/' : '.', ltrim($path, '/'));
        $node     = $data;
        foreach ($segments as $segment) {
            if (!is_array($node) || !isset($node[$segment])) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }
}
