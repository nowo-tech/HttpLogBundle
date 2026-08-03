<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\MessageHandler\PersistHttpLogHandler;
use Nowo\HttpLogBundle\Model\HttpLogCapture;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PersistHttpLogHandlerTest extends TestCase
{
    #[Test]
    public function existingRequestIdSkipsPersist(): void
    {
        $existing = (new HttpLogEntry())->setRequestId('existing-req');

        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['requestId' => 'existing-req'])
            ->willReturn($existing);

        $recorder = $this->createMock(HttpLogRecorder::class);
        $recorder->expects(self::never())->method('persistCapture');

        $handler = new PersistHttpLogHandler(
            $repository,
            $recorder,
            $this->createMock(LoggerInterface::class),
        );

        ($handler)(new PersistHttpLogMessage($this->payload('existing-req')));
    }

    #[Test]
    public function newEntryCallsRecorderPersistCapture(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['requestId' => 'new-req'])
            ->willReturn(null);

        $recorder = $this->createMock(HttpLogRecorder::class);
        $recorder->expects(self::once())
            ->method('persistCapture')
            ->with(self::callback(static fn (HttpLogCapture $capture): bool => $capture->requestId === 'new-req' && $capture->path === '/api/new'));

        $handler = new PersistHttpLogHandler(
            $repository,
            $recorder,
            $this->createMock(LoggerInterface::class),
        );

        ($handler)(new PersistHttpLogMessage($this->payload('new-req')));
    }

    #[Test]
    public function nullRequestIdAlwaysPersists(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->expects(self::never())->method('findOneBy');

        $recorder = $this->createMock(HttpLogRecorder::class);
        $recorder->expects(self::once())->method('persistCapture');

        $handler = new PersistHttpLogHandler(
            $repository,
            $recorder,
            $this->createMock(LoggerInterface::class),
        );

        $payload              = $this->payload('ignored');
        $payload['requestId'] = null;
        ($handler)(new PersistHttpLogMessage($payload));
    }

    #[Test]
    public function uniqueConstraintViolationIsLoggedAsDebug(): void
    {
        $repository = $this->createMock(HttpLogEntryRepository::class);
        $repository->method('findOneBy')->willReturn(null);

        $recorder = $this->createMock(HttpLogRecorder::class);
        $recorder->method('persistCapture')
            ->willThrowException($this->createMock(UniqueConstraintViolationException::class));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'HTTP log entry already persisted.',
                self::callback(static fn (array $context): bool => ($context['requestId'] ?? null) === 'dup-req'),
            );

        $handler = new PersistHttpLogHandler($repository, $recorder, $logger);

        ($handler)(new PersistHttpLogMessage($this->payload('dup-req')));
    }

    /** @return array<string, mixed> */
    private function payload(string $requestId): array
    {
        return (new HttpLogCapture(
            requestId: $requestId,
            method: 'GET',
            scheme: 'https',
            host: 'example.test',
            path: '/api/new',
            routeName: null,
            queryParams: null,
            statusCode: 200,
            clientIp: null,
            contentType: null,
            bodyContentType: null,
            durationMs: 1.0,
            userIdentifier: null,
            requestHeaders: null,
            responseHeaders: null,
            requestBody: null,
            responseBody: null,
            requestBodyTruncated: false,
            responseBodyTruncated: false,
            responseBodyStored: false,
            createdAt: new DateTimeImmutable('2026-08-03T10:00:00+00:00'),
        ))->toArray();
    }
}
