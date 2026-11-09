<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Usage;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;

final class UsageCaptureTest extends TestCase
{
    #[Test]
    public function payloadCarriesOnlyTheAllowlistedFields(): void
    {
        $capture = new UsageCapture(
            channel: 'outbound',
            createdAt: new DateTimeImmutable('2026-11-08T10:00:00+00:00'),
            method: 'post',
            path: '/v2/{x}',
            provider: 'clickup',
            scheme: 'https',
            host: 'api.clickup.com',
            statusCode: 201,
            durationMs: 12.5,
            responseBytes: 10,
            retryCount: 1,
            contentType: 'application/json; charset=utf-8',
            tenantKey: 'org-1',
            projectKey: 'project-1',
            messengerMessageId: '0123456789abcdef0123456789abcdef',
            eventId: 'fedcba9876543210fedcba9876543210',
        );

        $payload = $capture->toArray();
        self::assertSame([
            'channel', 'eventId', 'provider', 'method', 'scheme', 'host', 'path', 'statusCode', 'errorKind', 'durationMs',
            'responseBytes', 'retryCount', 'contentType', 'tenantKey', 'projectKey', 'messengerMessageId',
            'externalMessageId', 'createdAt',
        ], array_keys($payload));
        self::assertSame('POST', $payload['method']);
        self::assertSame('application/json', $payload['contentType']);
        self::assertFalse($capture->isError());

        $copy = UsageCapture::fromArray($payload);
        self::assertSame($payload, $copy->toArray());
    }

    #[Test]
    public function invalidValuesBecomeNullAndAnInvalidEventIdIsReplaced(): void
    {
        $capture = new UsageCapture(
            channel: 'mailer',
            createdAt: new DateTimeImmutable(),
            method: 'SEND',
            tenantKey: 'Bearer test-token-placeholder',
            eventId: 'client-chosen',
        );

        self::assertNull($capture->tenantKey);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $capture->eventId);
        self::assertSame('/', $capture->path);
    }

    #[Test]
    public function errorIsAKindOrAStatusOfAtLeast400(): void
    {
        $now = new DateTimeImmutable();

        self::assertTrue((new UsageCapture('outbound', $now, 'GET', statusCode: 404))->isError());
        self::assertTrue((new UsageCapture('outbound', $now, 'GET', errorKind: 'timeout'))->isError());
        self::assertFalse((new UsageCapture('outbound', $now, 'GET', statusCode: 302))->isError());
    }

    #[Test]
    public function channelMustBeAUsageChannel(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UsageCapture('web', new DateTimeImmutable(), 'GET');
    }

    #[Test]
    public function invalidChannelNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UsageCapture('Not A Channel', new DateTimeImmutable(), 'GET');
    }

    #[Test]
    public function fromArrayNeedsChannelAndTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UsageCapture::fromArray(['channel' => 'outbound']);
    }

    #[Test]
    public function fromArrayRejectsAnInvalidTime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UsageCapture::fromArray(['channel' => 'outbound', 'createdAt' => 'not a date']);
    }

    #[Test]
    public function fromArrayDefaultsMissingFields(): void
    {
        $capture = UsageCapture::fromArray(['channel' => 'outbound', 'createdAt' => '2026-11-08T10:00:00+00:00', 'host' => 7]);

        self::assertSame('OTHER', $capture->method);
        self::assertSame('/{…}', $capture->path);
        self::assertNull($capture->host);
    }
}
