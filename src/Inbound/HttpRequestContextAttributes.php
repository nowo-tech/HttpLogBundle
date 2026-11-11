<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use WeakMap;

use function array_key_exists;

/**
 * Holds the raw values of the host-allowlisted request attributes (`redaction.context_attributes`) of each
 * {@see HttpRequestContext} outside the object, so no dump of the context (VarDumper / `dump()`, `var_export()`,
 * `print_r()`, `(array)` cast, `json_encode()`, `serialize()`) can reach them.
 *
 * Keyed by the context object in a {@see WeakMap}: an entry disappears with its context, so nothing outlives the
 * request in a FrankenPHP worker (no reset needed, REQ-RUNTIME-002).
 *
 * @internal
 */
final class HttpRequestContextAttributes
{
    /** @var WeakMap<HttpRequestContext, array<string, mixed>>|null */
    private static ?WeakMap $values = null; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (WeakMap keyed by the per-request VO)

    /**
     * Stores the allowlisted attribute values of a context.
     *
     * @param array<string, mixed> $attributes attribute name => raw value
     */
    public static function put(HttpRequestContext $context, array $attributes): void
    {
        // A WeakMap keyed by the per-request VO: each entry dies with its context, nothing crosses requests.
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values ??= new WeakMap(); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
        // @igor-ignore - WeakMap keyed by the per-request VO; entries are collected with it.
        self::$values[$context] = $attributes; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
    }

    /** True when the context carries this allowlisted attribute (even with a null value). */
    public static function has(HttpRequestContext $context, string $name): bool
    {
        return isset(self::$values[$context]) && array_key_exists($name, self::$values[$context]);
    }

    /** The raw value of one allowlisted attribute, or null when it is absent (or after `clone` / `unserialize()`). */
    public static function get(HttpRequestContext $context, string $name): mixed
    {
        return self::$values[$context][$name] ?? null;
    }
}
