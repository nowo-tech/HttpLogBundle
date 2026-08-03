<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use Nowo\HttpLogBundle\Enum\BodyContentType;
use Symfony\Component\HttpFoundation\Response;

use function strlen;
use function substr;

/**
 * Decides whether request/response bodies are stored and truncated.
 */
final class CapturePolicy
{
    /**
     * @param array<string, mixed> $captureConfig
     */
    public function __construct(
        private readonly array $captureConfig,
    ) {
    }

    public function shouldCaptureRequestBody(): bool
    {
        return (bool) ($this->captureConfig['request_body'] ?? false);
    }

    /**
     * @return array{stored: bool, truncated: bool, body: ?string}
     */
    public function evaluateRequestBody(?string $body): array
    {
        if (!$this->shouldCaptureRequestBody() || $body === null || $body === '') {
            return ['stored' => false, 'truncated' => false, 'body' => null];
        }

        return $this->truncateBody($body);
    }

    /**
     * @return array{stored: bool, truncated: bool, body: ?string}
     */
    public function evaluateResponseBody(?string $body, BodyContentType $contentType): array
    {
        $byType = $this->captureConfig['response_body_by_type'] ?? [];
        $key    = $contentType->value;

        if (!($byType[$key] ?? false)) {
            return ['stored' => false, 'truncated' => false, 'body' => null];
        }

        if ($body === null || $body === '') {
            return ['stored' => false, 'truncated' => false, 'body' => null];
        }

        $result           = $this->truncateBody($body);
        $result['stored'] = true;

        return $result;
    }

    public function classifyResponseContentType(Response $response): ?string
    {
        return $response->headers->get('Content-Type');
    }

    /**
     * @return array{stored: bool, truncated: bool, body: ?string}
     */
    private function truncateBody(string $body): array
    {
        $maxBytes = (int) ($this->captureConfig['max_body_bytes'] ?? 65536);

        if ($maxBytes === 0) {
            return ['stored' => true, 'truncated' => false, 'body' => ''];
        }

        if (strlen($body) <= $maxBytes) {
            return ['stored' => true, 'truncated' => false, 'body' => $body];
        }

        return [
            'stored'    => true,
            'truncated' => true,
            'body'      => substr($body, 0, $maxBytes),
        ];
    }
}
