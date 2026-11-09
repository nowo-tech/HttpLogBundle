<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Mailer;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Context\HttpLogFrame;
use Nowo\HttpLogBundle\Enum\OutboundErrorKind;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\HttpLogChannel;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageFieldGuard;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\FailedMessageEvent;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Event\SentMessageEvent;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use WeakMap;

use function count;
use function hrtime;

/**
 * Mailer capture (HL-1 §12): one `mailer` row per send attempt the transport events report (a failover transport
 * reports each attempt). Never reads the envelope, recipients, subject, headers, body or attachments: only the
 * transport name (scheme and host, no credentials), the outcome, and the Message-ID of a sent mail.
 *
 * Pending starts live in a WeakMap keyed by the message object: a send that never completes leaves nothing behind;
 * `kernel.reset` empties it.
 */
final class MailerCaptureListener implements EventSubscriberInterface, ResetInterface
{
    /** @var WeakMap<RawMessage, array{startedAt: float|int, createdAt: DateTimeImmutable, scheme: string|null, host: string|null, frame: HttpLogFrame}> */
    private WeakMap $pending;

    private readonly LoggerInterface $logger;

    /**
     * @param array<string, string> $providers transport scheme => provider key (`mailer.providers`)
     */
    public function __construct(
        private readonly HttpLogBuffer $buffer,
        private readonly HttpLogContext $context,
        private readonly ClockInterface $clock,
        private readonly array $providers = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->pending = new WeakMap();
        $this->logger  = $logger ?? new NullLogger();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageEvent::class       => ['onMessage', -1024],
            SentMessageEvent::class   => ['onSent', -1024],
            FailedMessageEvent::class => ['onFailed', -1024],
        ];
    }

    public function onMessage(MessageEvent $event): void
    {
        if ($event->isQueued()) {
            return; // recorded when the worker sends it
        }

        try {
            $transport                           = MailerTransportParser::parse($event->getTransport());
            $this->pending[$event->getMessage()] = [
                'startedAt' => hrtime(true),
                'createdAt' => DateTimeImmutable::createFromInterface($this->clock->now()),
                'scheme'    => $transport['scheme'],
                'host'      => $transport['host'],
                'frame'     => $this->context->current(),
            ];
        } catch (Throwable $exception) {
            $this->warn($exception);
        }
    }

    public function onSent(SentMessageEvent $event): void
    {
        try {
            $sent = $event->getMessage();
            $this->record($sent->getOriginalMessage(), null, null, $sent->getMessageId());
        } catch (Throwable $exception) {
            $this->warn($exception);
        }
    }

    public function onFailed(FailedMessageEvent $event): void
    {
        try {
            $error  = $event->getError();
            $status = null;
            if ($error instanceof HttpTransportException) {
                try {
                    $status = UsageFieldGuard::statusCode($error->getResponse()->getStatusCode());
                } catch (Throwable) {
                    // no usable response: a transport error without status
                }
            }
            $kind = match (true) {
                $error instanceof HttpTransportException      => OutboundErrorKind::fromStatus($status) ?? OutboundErrorKind::Transport,
                $error instanceof TransportExceptionInterface => OutboundErrorKind::Transport,
                default                                       => OutboundErrorKind::Exception,
            };

            $this->record($event->getMessage(), $kind, $kind === OutboundErrorKind::Transport ? null : $status, null);
        } catch (Throwable $exception) {
            $this->warn($exception);
        }
    }

    /** Sends started and not finished yet (worker tests). */
    public function pendingCount(): int
    {
        return count($this->pending);
    }

    /** Forgets unfinished sends and writes the finished ones (the buffer is a kernel.reset service as well). */
    public function reset(): void
    {
        $this->pending = new WeakMap();
        $this->buffer->flush();
    }

    private function record(RawMessage $message, ?OutboundErrorKind $kind, ?int $status, ?string $messageId): void
    {
        $start = $this->pending[$message] ?? null;
        if ($start === null) {
            return; // started before this listener existed, or replaced by a later MessageEvent listener
        }
        unset($this->pending[$message]);

        $this->buffer->add(new UsageCapture(
            channel: HttpLogChannel::MAILER,
            createdAt: $start['createdAt'],
            method: 'SEND',
            path: '/',
            provider: $this->providers[(string) $start['scheme']] ?? $start['scheme'],
            scheme: $start['scheme'],
            host: $start['host'],
            statusCode: $status,
            errorKind: $kind?->value,
            durationMs: (hrtime(true) - $start['startedAt']) / 1e6,
            tenantKey: $start['frame']->tenantKey,
            projectKey: $start['frame']->projectKey,
            messengerMessageId: $start['frame']->messengerMessageId,
            externalMessageId: $messageId,
        ));
    }

    private function warn(Throwable $exception): void
    {
        $this->logger->warning('HTTP log mailer capture dropped.', ['exception_class' => $exception::class]);
    }
}
