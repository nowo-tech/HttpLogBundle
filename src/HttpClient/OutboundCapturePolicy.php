<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\HttpClient;

use Nowo\HttpLogBundle\Usage\UsageFieldGuard;

use function array_filter;
use function array_values;
use function in_array;
use function is_string;
use function random_int;
use function str_ends_with;
use function str_starts_with;
use function substr;

use const PHP_INT_MAX;

/**
 * The `nowo_http_log.outbound` configuration applied to one call (HL-1 §5.3, §5.5): which provider a call belongs to
 * and what its row stores, and whether the row is kept (errors always, successes by `sampling_rate`, never for a
 * `per_call: false` provider).
 *
 * Provider precedence: the per-request `extra.nowo_http_log.provider` when it names a configured provider, then the
 * provider a wrapped client was given, then the client service id, then exact hosts, then wildcard hosts (config
 * order), then `other` (or not recorded with `record_unmapped: false`).
 */
final readonly class OutboundCapturePolicy
{
    public const OTHER = 'other';

    /**
     * @param array<string, array{clients: list<string>, hosts: list<string>, path_templates: list<string>, path_depth: int|null, per_call: bool, rollup?: bool}> $providers
     */
    public function __construct(
        private array $providers = [],
        private float $samplingRate = 1.0,
        private bool $recordUnmapped = true,
        private int $defaultPathDepth = 2,
        /** @var list<string> extra path words kept verbatim (`outbound.path_words`) */
        private array $pathWords = [],
        /** @var list<string> hosts never captured, exact or "*.suffix" (`outbound.exclude_hosts`) */
        private array $excludeHosts = [],
        // rollup.enabled: every recorded call is a rollup event, with or without a row (HL-1 §5.5)
        private bool $rollupEnabled = false,
    ) {
    }

    /**
     * @param string|null $clientId service id of the decorated client (null for a wrapped client without one)
     * @param string|null $clientProvider provider given to {@see HttpLogHttpClientWrapper::wrap()}
     * @param mixed $requestProvider `extra.nowo_http_log.provider` of the request
     *
     * @return OutboundTarget|null null when the call is not recorded (unmapped host with `record_unmapped: false`)
     */
    public function target(string $url, ?string $clientId = null, ?string $clientProvider = null, mixed $requestProvider = null): ?OutboundTarget
    {
        $parsed = OutboundUrlTemplater::parse($url);
        if ($parsed === null) {
            // unparsable (or relative, after a synchronous failure): the client id still names the provider (IX-I2)
            $provider = $this->resolve('', $clientId, $clientProvider, $requestProvider) ?? self::OTHER;

            return new OutboundTarget($provider, null, OutboundUrlTemplater::INVALID_HOST, UsageFieldGuard::COLLAPSED_PATH, $this->providers[$provider]['per_call'] ?? true, $this->countsInRollup($provider));
        }
        if ($this->hostMatches($parsed['host'], $this->excludeHosts)) {
            return null; // e.g. the Messenger transport or database host (IX-L3)
        }

        $provider = $this->resolve($parsed['host'], $clientId, $clientProvider, $requestProvider);
        if ($provider === null) {
            return null;
        }

        $config = $this->providers[$provider] ?? null;
        $depth  = $config['path_depth'] ?? $this->defaultPathDepth;
        $path   = OutboundUrlTemplater::path($parsed['segments'], $config['path_templates'] ?? [], $depth, $this->pathWords);

        return new OutboundTarget($provider, $parsed['scheme'], $parsed['host'], $path, $config['per_call'] ?? true, $this->countsInRollup($provider));
    }

    /** Unmapped calls (`other`) count like a provider with the default `rollup: true`. */
    private function countsInRollup(string $provider): bool
    {
        return $this->rollupEnabled && ($this->providers[$provider]['rollup'] ?? true);
    }

    /** Errors are always kept; successes by `sampling_rate`; `per_call: false` providers never get a row. */
    public function keep(OutboundTarget $target, bool $isError): bool
    {
        if (!$target->perCall) {
            return false;
        }
        if ($isError || $this->samplingRate >= 1.0) {
            return true;
        }
        if ($this->samplingRate <= 0.0) {
            return false;
        }

        return random_int(0, PHP_INT_MAX) / PHP_INT_MAX <= $this->samplingRate;
    }

    private function resolve(string $host, ?string $clientId, ?string $clientProvider, mixed $requestProvider): ?string
    {
        if (is_string($requestProvider) && isset($this->providers[$requestProvider])) {
            return $requestProvider;
        }
        if ($clientProvider !== null) {
            return $clientProvider;
        }
        if ($clientId !== null) {
            foreach ($this->providers as $key => $provider) {
                if (in_array($clientId, $provider['clients'], true)) {
                    return $key;
                }
            }
        }
        foreach ($this->providers as $key => $provider) {
            if (in_array($host, $provider['hosts'], true)) {
                return $key;
            }
        }
        foreach ($this->providers as $key => $provider) {
            $wildcards = array_values(array_filter($provider['hosts'], static fn (string $p): bool => str_starts_with($p, '*.')));
            if ($host !== '' && $this->hostMatches($host, $wildcards)) {
                return $key;
            }
        }

        return $this->recordUnmapped ? self::OTHER : null;
    }

    /**
     * Exact hosts, or "*.example.com" (one or more labels before ".example.com", not the bare domain).
     *
     * @param list<string> $patterns
     */
    private function hostMatches(string $host, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $host || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)))) {
                return true;
            }
        }

        return false;
    }
}
