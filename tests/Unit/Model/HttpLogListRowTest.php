<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Model;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Model\HttpLogListRow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** PERF-1147-02: a list row is built from the scalar projection only (no headers, bodies or query string). */
final class HttpLogListRowTest extends TestCase
{
    #[Test]
    public function aRowReadsTheProjectedFields(): void
    {
        $row = HttpLogListRow::fromArray([
            'id'             => '12',
            'createdAt'      => new DateTimeImmutable('2026-08-03 10:00:00'),
            'method'         => 'POST',
            'path'           => '/api/items',
            'statusCode'     => '201',
            'durationMs'     => '3.25',
            'clientIp'       => '192.0.2.1',
            'channel'        => 'outbound',
            'provider'       => 'stripe',
            'routeName'      => 'items_create',
            'host'           => 'api.example.test',
            'scheme'         => 'https',
            'userIdentifier' => 'user-1',
            'requestId'      => 'req-1',
        ]);

        self::assertSame(12, $row->getId());
        self::assertSame('2026-08-03 10:00:00', $row->createdAt->format('Y-m-d H:i:s'));
        self::assertSame(['POST', '/api/items', 201, 3.25, '192.0.2.1'], [$row->method, $row->path, $row->statusCode, $row->durationMs, $row->clientIp]);
        self::assertSame(['outbound', 'stripe', 'items_create', 'api.example.test', 'https', 'user-1', 'req-1'], [$row->channel, $row->provider, $row->routeName, $row->host, $row->scheme, $row->userIdentifier, $row->requestId]);
    }

    #[Test]
    public function missingAndNullFieldsStayNull(): void
    {
        $row = HttpLogListRow::fromArray(['id' => 3, 'createdAt' => '2026-08-03 10:00:00', 'method' => 'GET', 'path' => '/', 'statusCode' => null, 'clientIp' => null]);

        self::assertSame('2026-08-03 10:00:00', $row->createdAt->format('Y-m-d H:i:s'));
        self::assertNull($row->statusCode);
        self::assertNull($row->durationMs);
        self::assertNull($row->clientIp);
        self::assertNull($row->channel);
        self::assertNull($row->routeName);
    }

    #[Test]
    public function theProjectionNeverSelectsHeadersBodiesOrTheQueryString(): void
    {
        foreach ([...HttpLogListRow::FIELDS, ...HttpLogListRow::USAGE_FIELDS] as $field) {
            self::assertDoesNotMatchRegularExpression('/body|headers|queryParams/i', $field);
        }
    }
}
