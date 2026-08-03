<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Model;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Model\HttpLogCapture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpLogCaptureTest extends TestCase
{
    #[Test]
    public function toArrayAndFromArrayRoundtripPreservesData(): void
    {
        $original = new HttpLogCapture(
            requestId: 'req-roundtrip',
            method: 'PUT',
            scheme: 'https',
            host: 'api.example.test',
            path: '/v1/resources/42',
            routeName: 'resource_update',
            queryParams: ['dryRun' => '1'],
            statusCode: 204,
            clientIp: '192.168.1.10',
            contentType: 'application/json',
            bodyContentType: 'json',
            durationMs: 33.3,
            userIdentifier: 'editor@example.test',
            requestHeaders: ['Content-Type' => ['application/json']],
            responseHeaders: ['X-Request-Id' => ['req-roundtrip']],
            requestBody: '{"name":"updated"}',
            responseBody: null,
            requestBodyTruncated: false,
            responseBodyTruncated: false,
            responseBodyStored: false,
            createdAt: new DateTimeImmutable('2026-06-01T08:30:00+00:00'),
        );

        $restored = HttpLogCapture::fromArray($original->toArray());

        self::assertSame($original->requestId, $restored->requestId);
        self::assertSame($original->method, $restored->method);
        self::assertSame($original->scheme, $restored->scheme);
        self::assertSame($original->host, $restored->host);
        self::assertSame($original->path, $restored->path);
        self::assertSame($original->routeName, $restored->routeName);
        self::assertSame($original->queryParams, $restored->queryParams);
        self::assertSame($original->statusCode, $restored->statusCode);
        self::assertSame($original->clientIp, $restored->clientIp);
        self::assertSame($original->contentType, $restored->contentType);
        self::assertSame($original->bodyContentType, $restored->bodyContentType);
        self::assertSame($original->durationMs, $restored->durationMs);
        self::assertSame($original->userIdentifier, $restored->userIdentifier);
        self::assertSame($original->requestHeaders, $restored->requestHeaders);
        self::assertSame($original->responseHeaders, $restored->responseHeaders);
        self::assertSame($original->requestBody, $restored->requestBody);
        self::assertSame($original->responseBody, $restored->responseBody);
        self::assertSame($original->requestBodyTruncated, $restored->requestBodyTruncated);
        self::assertSame($original->responseBodyTruncated, $restored->responseBodyTruncated);
        self::assertSame($original->responseBodyStored, $restored->responseBodyStored);
        self::assertSame(
            $original->createdAt->format(DateTimeImmutable::ATOM),
            $restored->createdAt->format(DateTimeImmutable::ATOM),
        );
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingFields(): void
    {
        $capture = HttpLogCapture::fromArray([]);

        self::assertSame('GET', $capture->method);
        self::assertSame('/', $capture->path);
        self::assertNull($capture->requestId);
        self::assertFalse($capture->requestBodyTruncated);
        self::assertInstanceOf(DateTimeImmutable::class, $capture->createdAt);
    }
}
