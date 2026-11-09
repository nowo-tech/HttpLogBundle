<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\HttpClient;

use Nowo\HttpLogBundle\DependencyInjection\Configuration;
use Nowo\HttpLogBundle\Usage\UsageFieldGuard;

use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function idn_to_ascii;
use function implode;
use function is_string;
use function max;
use function mb_strlen;
use function min;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function str_starts_with;
use function strtolower;

use const IDNA_DEFAULT;
use const INTL_IDNA_VARIANT_UTS46;

/**
 * Turns an outbound URL into what a row may store (HL-1 §5.3). Pure.
 *
 * - query string, fragment and user info are dropped, never redacted;
 * - the host is lower-cased (IDN as punycode), the port kept only when it is not the scheme's default;
 * - the path becomes a template: the first provider `path_templates` entry whose literal segments match, else the
 *   first `path_depth` segments, each kept verbatim only when it looks like a word (`v2`, `chat.postMessage`), else
 *   `{uuid}`, `{id}` (digits) or `{x}` (tokens, hashes, keys, e-mail addresses); the rest collapses to `/{…}`.
 */
final class OutboundUrlTemplater
{
    /** Host stored for a URL that cannot be parsed. */
    public const INVALID_HOST = 'invalid';

    public const REST = '{…}';

    private const WORD = '/^[A-Za-z][A-Za-z_.-]{0,31}[0-9]{0,2}\z/';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i';

    private const DIGITS = '/^[0-9]+\z/';

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443, 'ws' => 80, 'wss' => 443];

    /**
     * @return array{scheme: string|null, host: string, segments: list<string>}|null null when the URL cannot be parsed
     */
    public static function parse(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : null;
        $host   = self::host($parts['host']);
        if ($host === null) {
            return null;
        }
        if (isset($parts['port']) && ($scheme === null || (self::DEFAULT_PORTS[$scheme] ?? null) !== $parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        $segments = [];
        foreach (explode('/', $parts['path'] ?? '') as $segment) {
            if ($segment !== '') {
                $segments[] = rawurldecode($segment);
            }
        }

        return ['scheme' => $scheme, 'host' => $host, 'segments' => $segments];
    }

    /**
     * Path template of decoded segments: a matching provider template, else the depth-limited classification.
     *
     * @param list<string> $segments percent-decoded once
     * @param list<string> $templates provider `path_templates`
     */
    public static function path(array $segments, array $templates, int $depth): string
    {
        foreach ($templates as $template) {
            if (self::matches($template, $segments)) {
                return $template;
            }
        }

        if ($segments === []) {
            return '/';
        }

        $depth = max(0, min($depth, Configuration::MAX_PATH_SEGMENTS));
        $kept  = [];
        foreach (array_slice($segments, 0, $depth) as $segment) {
            $kept[] = self::segment($segment);
        }
        if (count($segments) > $depth) {
            $kept[] = self::REST;
        }

        $path = '/' . implode('/', $kept);

        return mb_strlen($path) <= UsageFieldGuard::MAX_PATH_LENGTH ? $path : UsageFieldGuard::COLLAPSED_PATH;
    }

    /** One segment kept verbatim only when it looks like a word, else a placeholder. */
    public static function segment(string $segment): string
    {
        return match (true) {
            preg_match(self::WORD, $segment) === 1   => $segment,
            preg_match(self::UUID, $segment) === 1   => '{uuid}',
            preg_match(self::DIGITS, $segment) === 1 => '{id}',
            default                                  => '{x}',
        };
    }

    /**
     * @param list<string> $segments
     */
    private static function matches(string $template, array $segments): bool
    {
        $parts = array_values(array_filter(explode('/', $template), static fn (string $s): bool => $s !== ''));
        if (count($parts) !== count($segments)) {
            return false;
        }
        foreach ($parts as $i => $part) {
            if (str_starts_with($part, '{')) {
                continue;
            }
            if ($part !== $segments[$i]) {
                return false;
            }
        }

        return true;
    }

    private static function host(string $host): ?string
    {
        $host = strtolower($host);
        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            // without ext-intl or symfony/polyfill-intl-idn this throws; the decorator then skips the capture
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($ascii)) {
                return null;
            }
            $host = $ascii;
        }

        return UsageFieldGuard::host($host) !== null ? $host : null;
    }
}
