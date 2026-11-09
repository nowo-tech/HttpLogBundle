<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Mailer;

use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Context\HttpLogFrame;
use Nowo\HttpLogBundle\Mailer\MailerCaptureListener;
use Nowo\HttpLogBundle\Mailer\MailerTransportParser;
use Nowo\HttpLogBundle\Tests\Support\TestMailerTransport;
use Nowo\HttpLogBundle\Tests\Support\UsageTestRig;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Messenger\MessageHandler;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Messenger\Envelope as MessengerEnvelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

use function array_column;

/**
 * HL-1 §12 / S3: one row per send attempt, from the transport events only.
 */
final class MailerCaptureListenerTest extends TestCase
{
    #[Test]
    public function transportNamesLoseTheirCredentials(): void
    {
        self::assertSame(['scheme' => 'smtp', 'host' => 'smtp.example:587'], MailerTransportParser::parse('smtp://user:test-password-placeholder@SMTP.example:587'));
        self::assertSame(['scheme' => 'resend+api', 'host' => 'default'], MailerTransportParser::parse('resend+api://test-key-placeholder@default'));
        self::assertSame(['scheme' => 'null', 'host' => null], MailerTransportParser::parse('null://'));
        self::assertSame(['scheme' => 'smtps', 'host' => 'mail.example'], MailerTransportParser::parse('smtps://mail.example/path?x=1'));
        self::assertSame(['scheme' => null, 'host' => null], MailerTransportParser::parse('failover(smtp://a smtp://b)'));
        self::assertSame(['scheme' => null, 'host' => null], MailerTransportParser::parse('not a transport'));
    }

    #[Test]
    public function aSentMailIsOneRowWithItsMessageIdAndProvider(): void
    {
        [$rig, $dispatcher] = $this->rig(['resend+api' => 'resend']);
        $transport          = new TestMailerTransport('resend+api://test-key-placeholder@api.resend.com', [], $dispatcher);

        $sent = $rig->context->runFrame(new HttpLogFrame('org-1', 'project-1', 'msg-1'), fn (): ?SentMessage => $transport->send($this->email()));
        $rig->persist();

        $rows = $rig->rows();
        self::assertCount(1, $rows);
        self::assertSame('mailer', $rows[0]['channel']);
        self::assertSame('resend', $rows[0]['provider']);
        self::assertSame('SEND', $rows[0]['method']);
        self::assertSame('/', $rows[0]['path']);
        self::assertSame('resend+api', $rows[0]['scheme']);
        self::assertSame('api.resend.com', $rows[0]['host']);
        self::assertNull($rows[0]['status_code']);
        self::assertNull($rows[0]['error_kind']);
        self::assertSame(trim((string) $sent?->getMessageId(), '<>'), $rows[0]['external_message_id']);
        self::assertSame(['org-1', 'project-1', 'msg-1'], [$rows[0]['tenant_key'], $rows[0]['project_key'], $rows[0]['messenger_message_id']]);
        self::assertSame(0, $rig->mailer->pendingCount());
    }

    #[Test]
    public function failuresGetAnErrorKindAndTheProviderStatus(): void
    {
        [$rig, $dispatcher] = $this->rig();
        $http422            = new HttpTransportException('Unable to send to ana@example.com', (new MockHttpClient(new MockResponse('{}', ['http_code' => 422])))->request('POST', 'https://api.resend.com/emails'));
        $http200            = new HttpTransportException('odd', (new MockHttpClient(new MockResponse('{}', ['http_code' => 200])))->request('POST', 'https://api.resend.com/emails'));
        $broken             = new HttpTransportException('no response', (new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host: api.resend.com'])))->request('POST', 'https://api.resend.com/emails'));
        $transport          = new TestMailerTransport('smtp://smtp.example:587', [$http422, $http200, $broken, new TransportException('Connection to ana@example.com refused'), new RuntimeException('bug')], $dispatcher);

        for ($i = 0; $i < 5; ++$i) {
            try {
                $transport->send($this->email());
            } catch (Throwable) {
            }
        }
        $rig->persist();

        self::assertSame(['http_4xx', 'transport', 'transport', 'transport', 'exception'], array_column($rig->rows(), 'error_kind'));
        self::assertSame([422, null, null, null, null], array_map(static fn (mixed $s): ?int => $s === null ? null : (int) $s, array_column($rig->rows(), 'status_code')));
        self::assertSame(['smtp'], array_values(array_unique(array_column($rig->rows(), 'provider'))), 'unmapped scheme = provider');

        [$other, $otherDispatcher] = $this->rig();
        (new TestMailerTransport('resend+smtp://smtp.resend.com', [], $otherDispatcher))->send($this->email());
        $other->persist();
        self::assertSame(['resend+smtp'], array_column($other->rows(), 'provider'));
    }

    #[Test]
    public function aFailoverTransportGivesOneRowPerAttempt(): void
    {
        [$rig, $dispatcher] = $this->rig();
        $failover           = new FailoverTransport([
            new TestMailerTransport('smtp://primary.example', [new TransportException('primary down')], $dispatcher),
            new TestMailerTransport('smtp://backup.example', [], $dispatcher),
        ]);

        $failover->send($this->email());
        $rig->persist();

        self::assertSame(['primary.example', 'backup.example'], array_column($rig->rows(), 'host'));
        self::assertSame(['transport', null], array_column($rig->rows(), 'error_kind'));
    }

    #[Test]
    public function aQueuedMailIsRecordedOnceWhenTheWorkerSendsIt(): void
    {
        [$rig, $dispatcher] = $this->rig();
        $transport          = new TestMailerTransport('smtp://smtp.example', [], $dispatcher);
        $queued             = [];
        $bus                = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$queued): MessengerEnvelope {
            $queued[] = $message;

            return new MessengerEnvelope($message);
        });

        (new Mailer($transport, $bus, $dispatcher))->send($this->email());
        self::assertSame(0, $rig->buffer->count(), 'queued: nothing yet');

        self::assertInstanceOf(SendEmailMessage::class, $queued[0]);
        (new MessageHandler($transport))($queued[0]); // the worker
        $rig->persist();

        self::assertCount(1, $rig->rows());
    }

    #[Test]
    public function eventsWithoutAStartAreIgnoredAndFailuresOfTheListenerAreSwallowed(): void
    {
        [$rig] = $this->rig();
        $email = $this->email();
        $rig->mailer->onSent(new SentMessageEvent(new SentMessage($email, Envelope::create($email))));
        $rig->mailer->onFailed(new FailedMessageEvent($email, new TransportException('x')));
        self::assertSame(0, $rig->buffer->count());

        $buffer = $this->createMock(HttpLogBuffer::class);
        $buffer->method('add')->willThrowException(new RuntimeException('buffer bug'));
        $listener = new MailerCaptureListener($buffer, $rig->context, $rig->clock, [], $rig->logger);
        $message  = new MessageEvent($email, Envelope::create($email), 'smtp://smtp.example');
        $listener->onMessage($message);
        $listener->onSent(new SentMessageEvent(new SentMessage($email, Envelope::create($email))));
        $listener->onMessage($message);
        $listener->onFailed(new FailedMessageEvent($email, new TransportException('x')));

        $context = $this->createMock(HttpLogContext::class);
        $context->method('current')->willThrowException(new RuntimeException('context bug'));
        (new MailerCaptureListener($rig->buffer, $context, $rig->clock))->onMessage($message);

        self::assertSame([RuntimeException::class, RuntimeException::class], array_column(array_column($rig->logs, 'context'), 'exception_class'));
    }

    #[Test]
    public function pendingStartsDoNotGrowAndResetForgetsThem(): void
    {
        [$rig, $dispatcher] = $this->rig();
        $transport          = new TestMailerTransport('smtp://smtp.example', [], $dispatcher);

        for ($i = 0; $i < 1000; ++$i) {
            $transport->send($this->email());
            $rig->buffer->flush();
            $rig->messages = [];
        }
        self::assertSame(0, $rig->mailer->pendingCount());

        $email = $this->email();
        $rig->mailer->onMessage(new MessageEvent($email, Envelope::create($email), 'smtp://smtp.example'));
        self::assertSame(1, $rig->mailer->pendingCount());
        unset($email);
        gc_collect_cycles();
        self::assertSame(0, $rig->mailer->pendingCount(), 'a send that never completes leaves nothing behind');

        $kept = $this->email();
        $rig->mailer->onMessage(new MessageEvent($kept, Envelope::create($kept), 'smtp://smtp.example'));
        $rig->mailer->reset();
        self::assertSame(0, $rig->mailer->pendingCount());
    }

    #[Test]
    public function subscribedEvents(): void
    {
        self::assertSame([
            MessageEvent::class       => ['onMessage', -1024],
            SentMessageEvent::class   => ['onSent', -1024],
            FailedMessageEvent::class => ['onFailed', -1024],
        ], MailerCaptureListener::getSubscribedEvents());
    }

    /**
     * @param array<string, string> $providers scheme => provider
     *
     * @return array{0: MailerRig, 1: EventDispatcher}
     */
    private function rig(array $providers = []): array
    {
        $rig        = new MailerRig($providers);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($rig->mailer);

        return [$rig, $dispatcher];
    }

    private function email(): Email
    {
        return (new Email())
            ->from(new Address('noreply@example.com', 'App'))
            ->to('ana@example.com')
            ->subject('Hello')
            ->text('Body');
    }
}

final readonly class MailerRig
{
    public MailerCaptureListener $mailer;

    private UsageTestRig $rig;

    /** @param array<string, string> $providers */
    public function __construct(array $providers)
    {
        $this->rig    = new UsageTestRig();
        $this->mailer = new MailerCaptureListener($this->rig->buffer, $this->rig->context, $this->rig->clock, $providers, $this->rig->logger);
    }

    public function __get(string $name): mixed
    {
        return $this->rig->{$name};
    }

    public function __set(string $name, mixed $value): void
    {
        $this->rig->{$name} = $value;
    }

    /** @return list<string> */
    public function persist(): array
    {
        return $this->rig->persist();
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->rig->rows();
    }
}
