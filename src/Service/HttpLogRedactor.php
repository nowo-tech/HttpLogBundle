<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use function count;
use function explode;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function json_last_error;
use function ltrim;
use function preg_match;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strcasecmp;
use function strtolower;
use function substr;
use function trim;
use function urldecode;
use function urlencode;

use const JSON_ERROR_NONE;
use const JSON_THROW_ON_ERROR;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Redacts sensitive headers, query parameters, and body fields (JSON, form-urlencoded, multipart).
 */
final class HttpLogRedactor
{
    public const PLACEHOLDER = '[REDACTED]';

    /** Stored instead of a body that looks structured (JSON) but cannot be parsed for redaction. */
    public const UNPARSEABLE_PLACEHOLDER = '[REDACTED-UNPARSEABLE]';

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

    /**
     * Redacts a full (not yet truncated) request/response body according to its Content-Type.
     *
     * JSON bodies are redacted by json_paths; form-urlencoded bodies and multipart text fields are
     * redacted by field name against json_paths leaf keys and query_params (case-insensitive).
     * A JSON-looking body that cannot be decoded is replaced by {@see self::UNPARSEABLE_PLACEHOLDER}
     * so secrets never leak verbatim. Other bodies (HTML, plain text, binary) are returned unchanged.
     */
    public function redactBody(?string $body, ?string $contentType): ?string
    {
        if ($body === null || $body === '' || ($this->jsonPaths === [] && $this->queryParamKeys === [])) {
            return $body;
        }

        $mimeType = strtolower(trim(explode(';', (string) $contentType)[0]));

        if ($mimeType === 'application/x-www-form-urlencoded') {
            return $this->redactFormUrlEncodedBody($body);
        }

        if ($mimeType === 'multipart/form-data') {
            return $this->redactMultipartBody($body, (string) $contentType);
        }

        if (!$this->isJsonMimeType($mimeType) && !$this->looksLikeJson($body)) {
            return $body;
        }

        if ($this->jsonPaths === []) {
            return $body;
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::UNPARSEABLE_PLACEHOLDER;
        }

        if (!is_array($decoded)) {
            return $body;
        }

        return json_encode($this->redactJsonValue($decoded), JSON_THROW_ON_ERROR);
    }

    private function isJsonMimeType(string $mimeType): bool
    {
        return $mimeType === 'application/json' || str_ends_with($mimeType, '+json');
    }

    private function looksLikeJson(string $body): bool
    {
        $trimmed = ltrim($body);

        return $trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[');
    }

    private function redactFormUrlEncodedBody(string $body): string
    {
        $pairs = explode('&', $body);
        foreach ($pairs as $index => $pair) {
            $name = urldecode(explode('=', $pair, 2)[0]);
            if ($this->shouldRedactFieldName($name)) {
                $pairs[$index] = explode('=', $pair, 2)[0] . '=' . urlencode(self::PLACEHOLDER);
            }
        }

        return implode('&', $pairs);
    }

    private function redactMultipartBody(string $body, string $contentType): string
    {
        if (preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $matches) !== 1) {
            return self::UNPARSEABLE_PLACEHOLDER;
        }

        $delimiter = '--' . ($matches[1] !== '' ? $matches[1] : $matches[2]);
        $parts     = explode($delimiter, $body);

        foreach ($parts as $index => $part) {
            $separator = str_contains($part, "\r\n\r\n") ? "\r\n\r\n" : "\n\n";
            $split     = explode($separator, $part, 2);
            if (count($split) !== 2) {
                continue;
            }

            [$headers, $content] = $split;
            if (preg_match('/name="([^"]*)"/i', $headers, $nameMatch) !== 1
                || preg_match('/filename\*?=/i', $headers) === 1
                || !$this->shouldRedactFieldName($nameMatch[1])
            ) {
                continue;
            }

            $lineEnding    = str_ends_with($content, "\r\n") ? "\r\n" : (str_ends_with($content, "\n") ? "\n" : '');
            $parts[$index] = $headers . $separator . self::PLACEHOLDER . $lineEnding;
        }

        return implode($delimiter, $parts);
    }

    /**
     * Matches every segment of a (possibly nested) field name, e.g. "login[_password]" or "user[auth][token]".
     */
    private function shouldRedactFieldName(string $name): bool
    {
        foreach (preg_split('/[\[\]]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
            if ($this->shouldRedactQueryParam($segment)) {
                return true;
            }

            foreach ($this->jsonPaths as $pattern) {
                $leaf = str_starts_with($pattern, '*.') ? substr($pattern, 2) : $pattern;
                if (strcasecmp($segment, $leaf) === 0) {
                    return true;
                }
            }
        }

        return false;
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
