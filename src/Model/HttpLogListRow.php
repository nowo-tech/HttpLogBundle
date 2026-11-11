<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Model;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;

use function is_numeric;
use function is_string;

/**
 * One row of the admin list (PERF-1147-02): only the columns the list shows, read by a scalar projection
 * ({@see HttpLogEntryRepository::findListRows()}). Never the query string, headers or bodies: those stay on the entry
 * page. `channel` and `provider` are null while the HL-1 usage columns are not mapped. The short request columns a
 * host's `index.html.twig` override may show (route, host, scheme, user identifier, request id) are read too.
 *
 * Twig reads the public properties (`row.id`, `row.path`, ...) like the entity getters it read before 1.17.
 */
final readonly class HttpLogListRow
{
    /** Entity fields the list projection selects (the usage ones only while mapped). */
    public const FIELDS = ['id', 'createdAt', 'method', 'path', 'statusCode', 'durationMs', 'clientIp', 'routeName', 'host', 'scheme', 'userIdentifier', 'requestId'];

    /** Usage fields the list projection adds while the HL-1 usage columns are mapped. */
    public const USAGE_FIELDS = ['channel', 'provider'];

    public function __construct(
        public int $id,
        public DateTimeImmutable $createdAt,
        public string $method,
        public string $path,
        public ?int $statusCode = null,
        public ?float $durationMs = null,
        public ?string $clientIp = null,
        public ?string $channel = null,
        public ?string $provider = null,
        public ?string $routeName = null,
        public ?string $host = null,
        public ?string $scheme = null,
        public ?string $userIdentifier = null,
        public ?string $requestId = null,
    ) {
    }

    /**
     * Row of an ORM array result (field name => PHP value, as converted by the column type).
     *
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $createdAt = $row['createdAt'] ?? null;

        return new self(
            (int) self::scalar($row['id'] ?? 0),
            $createdAt instanceof DateTimeImmutable ? $createdAt : new DateTimeImmutable(is_string($createdAt) ? $createdAt : '@0'),
            (string) self::scalar($row['method'] ?? ''),
            (string) self::scalar($row['path'] ?? ''),
            is_numeric($row['statusCode'] ?? null) ? (int) $row['statusCode'] : null,
            is_numeric($row['durationMs'] ?? null) ? (float) $row['durationMs'] : null,
            self::nullableString($row['clientIp'] ?? null),
            self::nullableString($row['channel'] ?? null),
            self::nullableString($row['provider'] ?? null),
            self::nullableString($row['routeName'] ?? null),
            self::nullableString($row['host'] ?? null),
            self::nullableString($row['scheme'] ?? null),
            self::nullableString($row['userIdentifier'] ?? null),
            self::nullableString($row['requestId'] ?? null),
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    private static function scalar(mixed $value): int|float|string
    {
        return is_string($value) || is_numeric($value) ? $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
