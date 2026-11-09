<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\HttpClient;

use Nowo\HttpLogBundle\Usage\UsageFieldGuard;

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
     * @param array<string, array{clients: list<string>, hosts: list<string>, path_templates: list<string>, path_depth: int|null, per_call: bool}> $providers
     */
    public function __construct(
        private array $providers = [],
        private float $samplingRate = 1.0,
        private bool $recordUnmapped = true,
        private int $defaultPathDepth = 2,
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
            return new OutboundTarget($clientProvider ?? self::OTHER, null, OutboundUrlTemplater::INVALID_HOST, UsageFieldGuard::COLLAPSED_PATH);
        }

        $provider = $this->resolve($parsed['host'], $clientId, $clientProvider, $requestProvider);
        if ($provider === null) {
            return null;
        }

        $config = $this->providers[$provider] ?? null;
        $depth  = $config['path_depth'] ?? $this->defaultPathDepth;
        $path   = OutboundUrlTemplater::path($parsed['segments'], $config['path_templates'] ?? [], $depth);

        return new OutboundTarget($provider, $parsed['scheme'], $parsed['host'], $path, $config['per_call'] ?? true);
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
            foreach ($provider['hosts'] as $pattern) {
                // "*.example.com" matches one or more labels before ".example.com", not the bare domain
                if (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1))) {
                    return $key;
                }
            }
        }

        return $this->recordUnmapped ? self::OTHER : null;
    }
}
