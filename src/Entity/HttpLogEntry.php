<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;

#[ORM\Entity(repositoryClass: HttpLogEntryRepository::class)]
#[ORM\Table(name: 'nowo_http_log_entry')]
#[ORM\Index(name: 'idx_http_log_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_http_log_route_name', columns: ['route_name'])]
#[ORM\Index(name: 'idx_http_log_status_code', columns: ['status_code'])]
#[ORM\Index(name: 'idx_http_log_client_ip', columns: ['client_ip'])]
class HttpLogEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (Doctrine sets id via reflection) */
    private ?int $id = null;

    #[ORM\Column(name: 'request_id', type: Types::STRING, length: 64, unique: true, nullable: true)]
    private ?string $requestId = null;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $method = 'GET';

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $scheme = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $host = null;

    #[ORM\Column(type: Types::STRING, length: 2048)]
    private string $path = '/';

    #[ORM\Column(name: 'route_name', type: Types::STRING, length: 255, nullable: true)]
    private ?string $routeName = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'query_params', type: Types::JSON, nullable: true)]
    private ?array $queryParams = null;

    #[ORM\Column(name: 'status_code', type: Types::INTEGER, nullable: true)]
    private ?int $statusCode = null;

    #[ORM\Column(name: 'client_ip', type: Types::STRING, length: 45, nullable: true)]
    private ?string $clientIp = null;

    #[ORM\Column(name: 'content_type', type: Types::STRING, length: 128, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(name: 'body_content_type', type: Types::STRING, length: 16, nullable: true)]
    private ?string $bodyContentType = null;

    #[ORM\Column(name: 'duration_ms', type: Types::FLOAT, nullable: true)]
    private ?float $durationMs = null;

    #[ORM\Column(name: 'user_identifier', type: Types::STRING, length: 255, nullable: true)]
    private ?string $userIdentifier = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'request_headers', type: Types::JSON, nullable: true)]
    private ?array $requestHeaders = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'response_headers', type: Types::JSON, nullable: true)]
    private ?array $responseHeaders = null;

    #[ORM\Column(name: 'request_body', type: Types::TEXT, nullable: true)]
    private ?string $requestBody = null;

    #[ORM\Column(name: 'response_body', type: Types::TEXT, nullable: true)]
    private ?string $responseBody = null;

    #[ORM\Column(name: 'request_body_truncated', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $requestBodyTruncated = false;

    #[ORM\Column(name: 'response_body_truncated', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $responseBodyTruncated = false;

    #[ORM\Column(name: 'response_body_stored', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $responseBodyStored = false;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setRequestId(?string $requestId): self
    {
        $this->requestId = $requestId;

        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    public function getScheme(): ?string
    {
        return $this->scheme;
    }

    public function setScheme(?string $scheme): self
    {
        $this->scheme = $scheme;

        return $this;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(?string $host): self
    {
        $this->host = $host;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    public function setRouteName(?string $routeName): self
    {
        $this->routeName = $routeName;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getQueryParams(): ?array
    {
        return $this->queryParams;
    }

    /** @param array<string, mixed>|null $queryParams */
    public function setQueryParams(?array $queryParams): self
    {
        $this->queryParams = $queryParams;

        return $this;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function setStatusCode(?int $statusCode): self
    {
        $this->statusCode = $statusCode;

        return $this;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function setClientIp(?string $clientIp): self
    {
        $this->clientIp = $clientIp;

        return $this;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(?string $contentType): self
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getBodyContentType(): ?string
    {
        return $this->bodyContentType;
    }

    public function setBodyContentType(?string $bodyContentType): self
    {
        $this->bodyContentType = $bodyContentType;

        return $this;
    }

    public function getDurationMs(): ?float
    {
        return $this->durationMs;
    }

    public function setDurationMs(?float $durationMs): self
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getUserIdentifier(): ?string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(?string $userIdentifier): self
    {
        $this->userIdentifier = $userIdentifier;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getRequestHeaders(): ?array
    {
        return $this->requestHeaders;
    }

    /** @param array<string, mixed>|null $requestHeaders */
    public function setRequestHeaders(?array $requestHeaders): self
    {
        $this->requestHeaders = $requestHeaders;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getResponseHeaders(): ?array
    {
        return $this->responseHeaders;
    }

    /** @param array<string, mixed>|null $responseHeaders */
    public function setResponseHeaders(?array $responseHeaders): self
    {
        $this->responseHeaders = $responseHeaders;

        return $this;
    }

    public function getRequestBody(): ?string
    {
        return $this->requestBody;
    }

    public function setRequestBody(?string $requestBody): self
    {
        $this->requestBody = $requestBody;

        return $this;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function setResponseBody(?string $responseBody): self
    {
        $this->responseBody = $responseBody;

        return $this;
    }

    public function isRequestBodyTruncated(): bool
    {
        return $this->requestBodyTruncated;
    }

    public function setRequestBodyTruncated(bool $requestBodyTruncated): self
    {
        $this->requestBodyTruncated = $requestBodyTruncated;

        return $this;
    }

    public function isResponseBodyTruncated(): bool
    {
        return $this->responseBodyTruncated;
    }

    public function setResponseBodyTruncated(bool $responseBodyTruncated): self
    {
        $this->responseBodyTruncated = $responseBodyTruncated;

        return $this;
    }

    public function isResponseBodyStored(): bool
    {
        return $this->responseBodyStored;
    }

    public function setResponseBodyStored(bool $responseBodyStored): self
    {
        $this->responseBodyStored = $responseBodyStored;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
