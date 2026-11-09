<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\HttpClient\LoggingHttpClient;
use Nowo\HttpLogBundle\HttpClient\OutboundCapturePolicy;
use Nowo\HttpLogBundle\HttpClient\OutboundClientFactory;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\MessageHandler\PersistHttpLogHandler;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\UsageCaptureSink;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use ReflectionClass;
use Stringable;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The HL-1 write path of a real host, on SQLite: capture → buffer → `PersistHttpLogMessage` (kept, serialized like a
 * transport would) → handler → DBAL row.
 */
final class UsageTestRig
{
    public readonly UsageTestDatabase $db;

    public readonly HttpLogContext $context;

    public readonly HttpLogBuffer $buffer;

    public readonly MockClock $clock;

    /** @var list<PersistHttpLogMessage> */
    public array $messages = [];

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $logs = [];

    public readonly AbstractLogger $logger;

    public function __construct()
    {
        $this->db      = new UsageTestDatabase();
        $this->context = new HttpLogContext();
        $this->clock   = new MockClock(new DateTimeImmutable('2026-11-08T10:00:00+00:00'));
        $messages      = &$this->messages;
        $bus           = new class($messages) implements MessageBusInterface {
            /** @param list<PersistHttpLogMessage> $messages */
            public function __construct(private array &$messages)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($message instanceof PersistHttpLogMessage) {
                    $this->messages[] = $message;
                }

                return new Envelope($message);
            }
        };
        $logs         = &$this->logs;
        $this->logger = new class($logs) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logs[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        $this->buffer = new HttpLogBuffer(new UsageCaptureSink(new UsageEntryWriter($this->db), true, $bus), $this->logger);
    }

    /**
     * @param array<string, array<string, mixed>> $providers `outbound.providers` (normalized defaults applied)
     */
    public function client(HttpClientInterface $inner, array $providers = [], float $samplingRate = 1.0, bool $recordUnmapped = true, ?string $clientId = null, ?string $provider = null): LoggingHttpClient
    {
        return $this->factory($providers, $samplingRate, $recordUnmapped)->create($inner, $clientId, $provider);
    }

    /**
     * @param array<string, array<string, mixed>> $providers
     */
    public function factory(array $providers = [], float $samplingRate = 1.0, bool $recordUnmapped = true): OutboundClientFactory
    {
        $normalized = [];
        foreach ($providers as $key => $provider) {
            $normalized[$key] = $provider + ['clients' => [], 'hosts' => [], 'path_templates' => [], 'path_depth' => null, 'per_call' => true];
        }

        return new OutboundClientFactory(new OutboundCapturePolicy($normalized, $samplingRate, $recordUnmapped, 2), $this->buffer, $this->context, $this->clock, $this->logger);
    }

    /**
     * Flushes the buffer, serializes each message as a transport would and persists it.
     *
     * @return list<string> the serialized message bodies
     */
    public function persist(): array
    {
        $this->buffer->flush();
        $serializer = new PhpSerializer();
        $handler    = new PersistHttpLogHandler($this->db->repository(), (new ReflectionClass(HttpLogRecorder::class))->newInstanceWithoutConstructor(), new NullLogger(), new UsageEntryWriter($this->db));
        $bodies     = [];
        foreach ($this->messages as $message) {
            $encoded  = $serializer->encode(new Envelope($message));
            $bodies[] = $encoded['body'];
            $decoded  = $serializer->decode($encoded)->getMessage();
            if ($decoded instanceof PersistHttpLogMessage) {
                $handler($decoded);
            }
        }
        $this->messages = [];

        return $bodies;
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->db->rows();
    }
}
