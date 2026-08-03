<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use Nowo\HttpLogBundle\Enum\BodyContentType;

use function preg_match;
use function str_contains;
use function stripos;
use function strtolower;

/**
 * Maps Content-Type headers and body sniffing to {@see BodyContentType}.
 */
final class ContentTypeClassifier
{
    public function classify(?string $contentType, ?string $body = null): BodyContentType
    {
        $normalized = $this->normalizeContentType($contentType);

        if ($normalized !== null) {
            if (str_contains($normalized, 'soap+xml')) {
                return BodyContentType::Soap;
            }

            if (str_contains($normalized, 'application/json') || str_contains($normalized, '+json')) {
                return BodyContentType::Json;
            }

            if (str_contains($normalized, 'text/html')) {
                return BodyContentType::Html;
            }

            if (str_contains($normalized, 'xml') || str_contains($normalized, 'application/soap')) {
                return BodyContentType::Xml;
            }

            if (str_starts_with($normalized, 'text/')) {
                return BodyContentType::Text;
            }

            if (
                str_contains($normalized, 'octet-stream')
                || str_starts_with($normalized, 'image/')
                || str_starts_with($normalized, 'audio/')
                || str_starts_with($normalized, 'video/')
            ) {
                return BodyContentType::Binary;
            }
        }

        if ($body !== null && $body !== '') {
            if (preg_match('/<\s*(?:[\w-]+:)?Envelope\b/i', $body) === 1) {
                return BodyContentType::Soap;
            }

            if (str_starts_with(ltrim($body), '{') || str_starts_with(ltrim($body), '[')) {
                return BodyContentType::Json;
            }

            if (stripos($body, '<html') !== false) {
                return BodyContentType::Html;
            }

            if (str_starts_with(ltrim($body), '<')) {
                return BodyContentType::Xml;
            }
        }

        return BodyContentType::Other;
    }

    private function normalizeContentType(?string $contentType): ?string
    {
        if ($contentType === null || $contentType === '') {
            return null;
        }

        $parts = explode(';', $contentType, 2);

        return strtolower(trim($parts[0]));
    }
}
