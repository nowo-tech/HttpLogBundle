<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error;
use function strcasecmp;

use const JSON_ERROR_NONE;
use const JSON_THROW_ON_ERROR;

/**
 * Redacts sensitive headers, query parameters, and JSON body keys.
 */
final class HttpLogRedactor
{
    /**
     * @param list<string> $headerKeys
     * @param list<string> $queryParamKeys
     * @param list<string> $jsonPaths
     */
    public function __construct(
        private readonly array $headerKeys,
        private readonly array $queryParamKeys,
        private readonly array $jsonPaths,
    ) {
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, mixed>
     */
    public function redactHeaders(array $headers): array
    {
        $redacted = [];
        foreach ($headers as $name => $value) {
            $redacted[$name] = $this->shouldRedactHeader((string) $name)
                ? '[REDACTED]'
                : $value;
        }

        return $redacted;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function redactQueryParams(array $params): array
    {
        $redacted = [];
        foreach ($params as $key => $value) {
            $redacted[$key] = $this->shouldRedactQueryParam((string) $key)
                ? '[REDACTED]'
                : $value;
        }

        return $redacted;
    }

    public function redactJsonBody(?string $body): ?string
    {
        if ($body === null || $body === '' || $this->jsonPaths === []) {
            return $body;
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $body;
        }

        $redacted = $this->redactJsonValue($decoded);

        return json_encode($redacted, JSON_THROW_ON_ERROR);
    }

    private function shouldRedactHeader(string $name): bool
    {
        foreach ($this->headerKeys as $key) {
            if (strcasecmp($name, $key) === 0) {
                return true;
            }
        }

        return false;
    }

    private function shouldRedactQueryParam(string $key): bool
    {
        foreach ($this->queryParamKeys as $paramKey) {
            if (strcasecmp($key, $paramKey) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function redactJsonValue(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->shouldRedactJsonKey($key)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->redactJsonValue($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function shouldRedactJsonKey(string $key): bool
    {
        foreach ($this->jsonPaths as $pattern) {
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 2);
                if ($key === $suffix) {
                    return true;
                }
            } elseif ($key === $pattern) {
                return true;
            }
        }

        return false;
    }
}
