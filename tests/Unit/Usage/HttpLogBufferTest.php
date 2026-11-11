<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Usage;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Privacy\HttpLogErasedSubjects;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubject;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageCaptureSink;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class HttpLogBufferTest extends TestCase
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $logs = [];

    /** @var list<object> */
    private array $dispatched = [];

    private bool $failDispatch = false;

    /** @var (callable(): void)|null */
    private $onDispatch;

    #[Test]
    public function capturesWaitForTheBoundary(): void
    {
        $buffer = $this->buffer();
        $buffer->add($this->capture());
        $buffer->add($this->capture());

        self::assertSame(2, $buffer->count());
        self::assertSame([], $this->dispatched);

        $buffer->flush();
        self::assertSame(0, $buffer->count());
        self::assertCount(2, $this->dispatched);
    }

    #[Test]
    public function aFullBatchFlushesOnAdd(): void
    {
        $buffer = $this->buffer();
        for ($i = 1; $i <= HttpLogBuffer::FLUSH_AT; ++$i) {
            $buffer->add($this->capture());
        }

        self::assertSame(0, $buffer->count());
        self::assertCount(HttpLogBuffer::FLUSH_AT, $this->dispatched);
    }

    #[Test]
    public function overflowIsDroppedAndCountedInOneWarning(): void
    {
        $buffer = $this->buffer();
        $buffer->suspended(function () use ($buffer): void {
            for ($i = 1; $i <= HttpLogBuffer::CAPACITY + 3; ++$i) {
                $buffer->add($this->capture());
            }
            // no flush while suspended
            $buffer->flush();
        });

        self::assertSame(HttpLogBuffer::CAPACITY, $buffer->count());
        $buffer->flush();

        self::assertCount(HttpLogBuffer::CAPACITY, $this->dispatched);
        self::assertSame([['warning', ['dropped' => 3, 'capacity' => HttpLogBuffer::CAPACITY]]], $this->logs);
    }

    #[Test]
    public function aFailingWriteIsDroppedWithTheExceptionClassOnly(): void
    {
        $this->failDispatch = true;
        $buffer             = $this->buffer();
        $buffer->add($this->capture());
        $buffer->flush();

        self::assertSame([['warning', ['channel' => 'outbound', 'exception_class' => RuntimeException::class]]], $this->logs);
        self::assertSame(0, $buffer->count());
    }

    #[Test]
    public function captureIsSuspendedWhileTheBufferWrites(): void
    {
        $buffer           = $this->buffer();
        $seen             = [];
        $this->onDispatch = static function () use ($buffer, &$seen): void {
            $seen[] = $buffer->isSuspended();
        };
        $buffer->add($this->capture());

        self::assertFalse($buffer->isSuspended());
        $buffer->flush();

        self::assertSame([true], $seen);
        self::assertFalse($buffer->isSuspended());
    }

    #[Test]
    public function capturesAddedWhileWritingAreWrittenInTheSameFlush(): void
    {
        $buffer           = $this->buffer();
        $added            = false;
        $this->onDispatch = function () use ($buffer, &$added): void {
            if (!$added) {
                $added = true;
                $buffer->add($this->capture());
            }
        };
        $buffer->add($this->capture());
        $buffer->flush();

        self::assertCount(2, $this->dispatched);
    }

    #[Test]
    public function resetFlushesThenForgets(): void
    {
        $buffer = $this->buffer();
        $buffer->add($this->capture());
        $buffer->reset();

        self::assertCount(1, $this->dispatched);
        self::assertSame(0, $buffer->count());
        self::assertFalse($buffer->isSuspended());
    }

    #[Test]
    public function suspendedReturnsTheCallableResultAndAlwaysResumes(): void
    {
        $buffer = $this->buffer();
        self::assertSame(5, $buffer->suspended(static fn (): int => 5));

        try {
            $buffer->suspended(static function (): never {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }
        self::assertFalse($buffer->isSuspended());
    }

    #[Test]
    public function worksWithoutALogger(): void
    {
        $this->failDispatch = true;
        $buffer             = new HttpLogBuffer($this->sink());
        $buffer->add($this->capture());
        $buffer->flush();

        self::assertSame(0, $buffer->count());
    }

    #[Test]
    public function capturesOfAValueErasedInTheBoundaryAreDroppedAtTheFlush(): void
    {
        $erased = new HttpLogErasedSubjects();
        $buffer = $this->buffer($erased);
        $buffer->add(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', userIdentifier: 'ana'));
        $buffer->add(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', clientIp: '10.0.0.1'));
        $buffer->add(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', userIdentifier: 'Ana', clientIp: '10.0.0.2'));
        $buffer->add($this->capture());

        // queued before the erasure, written after it (kernel.terminate): LY-L2
        $erased->remember(HttpLogPersonalDataSubject::fromUserIdentifier('ana'));
        $erased->remember(HttpLogPersonalDataSubject::fromClientIp('10.0.0.1'));
        $buffer->flush();

        self::assertCount(2, $this->dispatched, 'only the other person\'s capture and the anonymous one');
        self::assertSame(0, $buffer->count());

        $erased->reset();
        $buffer->add(new UsageCapture('outbound', new DateTimeImmutable(), 'GET', userIdentifier: 'ana'));
        $buffer->flush();
        self::assertCount(3, $this->dispatched, 'the next boundary records the user again');
    }

    private function buffer(?HttpLogErasedSubjects $erased = null): HttpLogBuffer
    {
        $logs   = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
            /** @param list<array{0: string, 1: array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logs[] = [(string) $level, $context];
            }
        };

        return new HttpLogBuffer($this->sink(), $logger, $erased);
    }

    private function sink(): UsageCaptureSink
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            if ($this->onDispatch !== null) {
                ($this->onDispatch)();
            }
            if ($this->failDispatch) {
                throw new RuntimeException('transport down with a secret in the message');
            }
            self::assertInstanceOf(PersistHttpLogMessage::class, $message);
            $this->dispatched[] = $message;

            return new Envelope($message);
        });

        return new UsageCaptureSink($this->createMock(UsageEntryWriter::class), true, $bus);
    }

    private function capture(): UsageCapture
    {
        return new UsageCapture('outbound', new DateTimeImmutable(), 'GET');
    }
}
