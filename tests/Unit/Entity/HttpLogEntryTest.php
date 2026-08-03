<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Entity;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HttpLogEntryTest extends TestCase
{
    #[Test]
    public function settersAndGettersSmokeTest(): void
    {
        $createdAt = new DateTimeImmutable('2026-03-10T14:00:00+00:00');
        $entry     = new HttpLogEntry();

        $entry
            ->setRequestId('req-smoke')
            ->setMethod('PATCH')
            ->setScheme('http')
            ->setHost('localhost')
            ->setPath('/admin/logs')
            ->setRouteName('admin_logs')
            ->setQueryParams(['page' => '2'])
            ->setStatusCode(404)
            ->setClientIp('::1')
            ->setContentType('text/html')
            ->setBodyContentType('html')
            ->setDurationMs(99.9)
            ->setUserIdentifier('admin')
            ->setRequestHeaders(['Accept' => ['text/html']])
            ->setResponseHeaders(['Content-Type' => ['text/html']])
            ->setRequestBody('<html></html>')
            ->setResponseBody('<html>404</html>')
            ->setRequestBodyTruncated(true)
            ->setResponseBodyTruncated(false)
            ->setResponseBodyStored(true)
            ->setCreatedAt($createdAt);

        self::assertNull($entry->getId());
        self::assertSame('req-smoke', $entry->getRequestId());
        self::assertSame('PATCH', $entry->getMethod());
        self::assertSame('http', $entry->getScheme());
        self::assertSame('localhost', $entry->getHost());
        self::assertSame('/admin/logs', $entry->getPath());
        self::assertSame('admin_logs', $entry->getRouteName());
        self::assertSame(['page' => '2'], $entry->getQueryParams());
        self::assertSame(404, $entry->getStatusCode());
        self::assertSame('::1', $entry->getClientIp());
        self::assertSame('text/html', $entry->getContentType());
        self::assertSame('html', $entry->getBodyContentType());
        self::assertSame(99.9, $entry->getDurationMs());
        self::assertSame('admin', $entry->getUserIdentifier());
        self::assertSame(['Accept' => ['text/html']], $entry->getRequestHeaders());
        self::assertSame(['Content-Type' => ['text/html']], $entry->getResponseHeaders());
        self::assertSame('<html></html>', $entry->getRequestBody());
        self::assertSame('<html>404</html>', $entry->getResponseBody());
        self::assertTrue($entry->isRequestBodyTruncated());
        self::assertFalse($entry->isResponseBodyTruncated());
        self::assertTrue($entry->isResponseBodyStored());
        self::assertSame($createdAt, $entry->getCreatedAt());
    }
}
