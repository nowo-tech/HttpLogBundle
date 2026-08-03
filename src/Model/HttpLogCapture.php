<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Model;

use DateTimeImmutable;
use DateTimeInterface;

use function is_array;
use function is_string;

/**
 * Serializable capture payload produced before persistence.
 */
final readonly class HttpLogCapture
{
    /**
     * @param array<string, mixed>|null $queryParams
     * @param array<string, mixed>|null $requestHeaders
     * @param array<string, mixed>|null $responseHeaders
     */
    public function __construct(
        public ?string $requestId,
        public string $method,
        public ?string $scheme,
        public ?string $host,
        public string $path,
        public ?string $routeName,
        public ?array $queryParams,
        public ?int $statusCode,
        public ?string $clientIp,
        public ?string $contentType,
        public ?string $bodyContentType,
        public ?float $durationMs,
        public ?string $userIdentifier,
        public ?array $requestHeaders,
        public ?array $responseHeaders,
        public ?string $requestBody,
        public ?string $responseBody,
        public bool $requestBodyTruncated,
        public bool $responseBodyTruncated,
        public bool $responseBodyStored,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'requestId'             => $this->requestId,
            'method'                => $this->method,
            'scheme'                => $this->scheme,
            'host'                  => $this->host,
            'path'                  => $this->path,
            'routeName'             => $this->routeName,
            'queryParams'           => $this->queryParams,
            'statusCode'            => $this->statusCode,
            'clientIp'              => $this->clientIp,
            'contentType'           => $this->contentType,
            'bodyContentType'       => $this->bodyContentType,
            'durationMs'            => $this->durationMs,
            'userIdentifier'        => $this->userIdentifier,
            'requestHeaders'        => $this->requestHeaders,
            'responseHeaders'       => $this->responseHeaders,
            'requestBody'           => $this->requestBody,
            'responseBody'          => $this->responseBody,
            'requestBodyTruncated'  => $this->requestBodyTruncated,
            'responseBodyTruncated' => $this->responseBodyTruncated,
            'responseBodyStored'    => $this->responseBodyStored,
            'createdAt'             => $this->createdAt->format(DateTimeInterface::ATOM),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $createdAt = isset($data['createdAt']) && is_string($data['createdAt'])
            ? new DateTimeImmutable($data['createdAt'])
            : new DateTimeImmutable();

        return new self(
            requestId: isset($data['requestId']) ? (string) $data['requestId'] : null,
            method: (string) ($data['method'] ?? 'GET'),
            scheme: isset($data['scheme']) ? (string) $data['scheme'] : null,
            host: isset($data['host']) ? (string) $data['host'] : null,
            path: (string) ($data['path'] ?? '/'),
            routeName: isset($data['routeName']) ? (string) $data['routeName'] : null,
            queryParams: isset($data['queryParams']) && is_array($data['queryParams']) ? $data['queryParams'] : null,
            statusCode: isset($data['statusCode']) ? (int) $data['statusCode'] : null,
            clientIp: isset($data['clientIp']) ? (string) $data['clientIp'] : null,
            contentType: isset($data['contentType']) ? (string) $data['contentType'] : null,
            bodyContentType: isset($data['bodyContentType']) ? (string) $data['bodyContentType'] : null,
            durationMs: isset($data['durationMs']) ? (float) $data['durationMs'] : null,
            userIdentifier: isset($data['userIdentifier']) ? (string) $data['userIdentifier'] : null,
            requestHeaders: isset($data['requestHeaders']) && is_array($data['requestHeaders']) ? $data['requestHeaders'] : null,
            responseHeaders: isset($data['responseHeaders']) && is_array($data['responseHeaders']) ? $data['responseHeaders'] : null,
            requestBody: isset($data['requestBody']) ? (string) $data['requestBody'] : null,
            responseBody: isset($data['responseBody']) ? (string) $data['responseBody'] : null,
            requestBodyTruncated: (bool) ($data['requestBodyTruncated'] ?? false),
            responseBodyTruncated: (bool) ($data['responseBodyTruncated'] ?? false),
            responseBodyStored: (bool) ($data['responseBodyStored'] ?? false),
            createdAt: $createdAt,
        );
    }
}
