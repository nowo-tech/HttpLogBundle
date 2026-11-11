<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;

use function mb_substr;

#[ORM\Entity(repositoryClass: HttpLogEntryRepository::class)]
#[ORM\Table(name: 'nowo_http_log_entry')]
// 1.17 (PERF-1147-02): the list reads (created_at, id) by keyset, so the default index carries the id tie-break and each
// filter index ends with created_at (filter + order + keyset bound from one index). Same names; see UPGRADING 1.17.
#[ORM\Index(name: 'idx_http_log_created_at', columns: ['created_at', 'id'])]
#[ORM\Index(name: 'idx_http_log_route_name', columns: ['route_name', 'created_at'])]
#[ORM\Index(name: 'idx_http_log_status_code', columns: ['status_code', 'created_at'])]
#[ORM\Index(name: 'idx_http_log_client_ip', columns: ['client_ip', 'created_at'])]
class HttpLogEntry
{
    /** Length of {@see self::$pathPrefix}: 191 characters keep a utf8mb4 index entry under 767 bytes. */
    public const PATH_PREFIX_LENGTH = 191;

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

    /**
     * First {@see self::PATH_PREFIX_LENGTH} characters of the path, the indexed column of the prefix filters (1.17,
     * `web_ui.filter_match: prefix`). NOT mapped by these attributes: PathPrefixColumnListener maps it only in that
     * mode, so other hosts keep their schema. Set with the path.
     */
    private ?string $pathPrefix = null;

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

    // Usage columns (HL-1, since 1.15): NOT mapped by these attributes. UsageColumnsListener maps them only when an
    // outbound or mailer feature is enabled, so hosts that enable nothing keep the 1.14 metadata and schema.

    /** @phpstan-ignore property.unusedType (hydrated by Doctrine once UsageColumnsListener maps it) */
    private ?string $channel = null;

    /** @phpstan-ignore property.unusedType (hydrated by Doctrine once UsageColumnsListener maps it) */
    private ?string $provider = null;

    /** @phpstan-ignore property.unusedType (hydrated by Doctrine once UsageColumnsListener maps it) */
    private ?string $credentialKind = null;

    /** @phpstan-ignore property.unusedType (hydrated by Doctrine once UsageColumnsListener maps it) */
    private ?string $credentialId = null;

    /** @phpstan-ignore property.unusedType (hydrated by Doctrine once UsageColumnsListener maps it) */
    private ?string $tenantKey = null;

    /** @phpstan-ignore property.unusedType (hydrated by Doctrine once UsageColumnsListener maps it) */
    private ?string $projectKey = null;

    /** @phpstan-ignore property.unusedType, property.unusedType (BIGINT: DBAL 3 hydrates a numeric string, DBAL 4 an int) */
    private int|string|null $responseBytes = null;

    /** @phpstan-ignore property.unusedType (hydrated by Doctrine once UsageColumnsListener maps it) */
    private ?int $retryCount = null;

    /** @phpstan-ignore property.unusedType (hydrated by Doctrine once UsageColumnsListener maps it) */
    private ?string $errorKind = null;

    /** @phpstan-ignore property.unusedType (hydrated by Doctrine once UsageColumnsListener maps it) */
    private ?string $messengerMessageId = null;

    /** @phpstan-ignore property.unusedType (hydrated by Doctrine once UsageColumnsListener maps it) */
    private ?string $externalMessageId = null;

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
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->requestId = $requestId;

        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->method = $method;

        return $this;
    }

    public function getScheme(): ?string
    {
        return $this->scheme;
    }

    public function setScheme(?string $scheme): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->scheme = $scheme;

        return $this;
    }

    public function getHost(): ?string
    {
        return $this->host;
    }

    public function setHost(?string $host): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->host = $host;

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->path = $path;
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->pathPrefix = self::pathPrefixOf($path);

        return $this;
    }

    /** Indexed path prefix of the prefix filters (null until a path is set). */
    public function getPathPrefix(): ?string
    {
        return $this->pathPrefix;
    }

    /** First {@see self::PATH_PREFIX_LENGTH} characters of a path (the value of the `path_prefix` column). */
    public static function pathPrefixOf(string $path): string
    {
        return mb_substr($path, 0, self::PATH_PREFIX_LENGTH);
    }

    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    public function setRouteName(?string $routeName): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
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
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->queryParams = $queryParams;

        return $this;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function setStatusCode(?int $statusCode): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->statusCode = $statusCode;

        return $this;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function setClientIp(?string $clientIp): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->clientIp = $clientIp;

        return $this;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(?string $contentType): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->contentType = $contentType;

        return $this;
    }

    public function getBodyContentType(): ?string
    {
        return $this->bodyContentType;
    }

    public function setBodyContentType(?string $bodyContentType): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->bodyContentType = $bodyContentType;

        return $this;
    }

    public function getDurationMs(): ?float
    {
        return $this->durationMs;
    }

    public function setDurationMs(?float $durationMs): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getUserIdentifier(): ?string
    {
        return $this->userIdentifier;
    }

    public function setUserIdentifier(?string $userIdentifier): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
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
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
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
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->responseHeaders = $responseHeaders;

        return $this;
    }

    public function getRequestBody(): ?string
    {
        return $this->requestBody;
    }

    public function setRequestBody(?string $requestBody): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->requestBody = $requestBody;

        return $this;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function setResponseBody(?string $responseBody): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->responseBody = $responseBody;

        return $this;
    }

    public function isRequestBodyTruncated(): bool
    {
        return $this->requestBodyTruncated;
    }

    public function setRequestBodyTruncated(bool $requestBodyTruncated): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->requestBodyTruncated = $requestBodyTruncated;

        return $this;
    }

    public function isResponseBodyTruncated(): bool
    {
        return $this->responseBodyTruncated;
    }

    public function setResponseBodyTruncated(bool $responseBodyTruncated): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->responseBodyTruncated = $responseBodyTruncated;

        return $this;
    }

    public function isResponseBodyStored(): bool
    {
        return $this->responseBodyStored;
    }

    public function setResponseBodyStored(bool $responseBodyStored): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->responseBodyStored = $responseBodyStored;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        // @igor-ignore - Doctrine entity instance state; not a FrankenPHP shared service
        $this->createdAt = $createdAt;

        return $this;
    }

    /** Channel of the row (`outbound`, `mailer`); null for web requests and when the usage columns are not mapped. */
    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getCredentialKind(): ?string
    {
        return $this->credentialKind;
    }

    public function getCredentialId(): ?string
    {
        return $this->credentialId;
    }

    public function getTenantKey(): ?string
    {
        return $this->tenantKey;
    }

    public function getProjectKey(): ?string
    {
        return $this->projectKey;
    }

    public function getResponseBytes(): ?int
    {
        return $this->responseBytes === null ? null : (int) $this->responseBytes;
    }

    public function getRetryCount(): ?int
    {
        return $this->retryCount;
    }

    public function getErrorKind(): ?string
    {
        return $this->errorKind;
    }

    public function getMessengerMessageId(): ?string
    {
        return $this->messengerMessageId;
    }

    public function getExternalMessageId(): ?string
    {
        return $this->externalMessageId;
    }
}
