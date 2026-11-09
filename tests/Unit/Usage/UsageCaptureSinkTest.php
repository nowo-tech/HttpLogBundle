<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Usage;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\UsageBufferFlushSubscriber;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageCaptureSink;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

final class UsageCaptureSinkTest extends TestCase
{
    #[Test]
    public function asyncDispatchesTheAllowlistedPayloadWithoutDispatchAfterCurrentBus(): void
    {
        $capture = new UsageCapture('outbound', new DateTimeImmutable(), 'GET');
        $bus     = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message, array $stamps = []) use ($capture): Envelope {
            self::assertInstanceOf(PersistHttpLogMessage::class, $message);
            self::assertSame($capture->toArray(), $message->payload);
            foreach ($stamps as $stamp) {
                self::assertNotInstanceOf(DispatchAfterCurrentBusStamp::class, $stamp);
            }

            return new Envelope($message);
        });
        $writer = $this->createMock(UsageEntryWriter::class);
        $writer->expects(self::never())->method('write');

        (new UsageCaptureSink($writer, true, $bus))->write($capture);
    }

    #[Test]
    public function syncWritesDirectly(): void
    {
        $capture = new UsageCapture('mailer', new DateTimeImmutable(), 'SEND');
        $writer  = $this->createMock(UsageEntryWriter::class);
        $writer->expects(self::once())->method('write')->with($capture)->willReturn(true);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        (new UsageCaptureSink($writer, false, $bus))->write($capture);
        // async without a bus (Messenger not configured) also writes directly
        $writer2 = $this->createMock(UsageEntryWriter::class);
        $writer2->expects(self::once())->method('write');
        (new UsageCaptureSink($writer2, true))->write($capture);
    }

    #[Test]
    public function flushSubscriberListensToEveryBoundary(): void
    {
        self::assertSame([
            KernelEvents::TERMINATE          => ['flush', -2048],
            'console.terminate'              => ['flush', -2048],
            WorkerMessageHandledEvent::class => ['flush', -2048],
            WorkerMessageFailedEvent::class  => ['flush', -2048],
        ], UsageBufferFlushSubscriber::getSubscribedEvents());

        $buffer = $this->createMock(HttpLogBuffer::class);
        $buffer->expects(self::once())->method('flush');
        (new UsageBufferFlushSubscriber($buffer))->flush();
    }
}
