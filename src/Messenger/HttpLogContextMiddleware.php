<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Messenger;

use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Context\HttpLogFrame;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Throwable;

use function is_int;
use function is_string;
use function str_starts_with;

/**
 * Service `nowo_http_log.messenger.context_middleware` (HL-1 §6.4); the host adds it to its buses.
 *
 * - On dispatch (no `ReceivedStamp`): adds one {@see HttpLogContextStamp} when the envelope has none, with a new
 *   message id and, with `messenger.propagate_tenant`, the current tenant / project. The stamp travels with the
 *   envelope, so the id is stable across retries and transports.
 * - On handle (`ReceivedStamp`): pushes a frame (tenant and project of the stamp, message id of the stamp or else the
 *   transport id) for the handler and pops it in `finally`.
 * - The bundle's own messages get neither a stamp nor a frame.
 */
final readonly class HttpLogContextMiddleware implements MiddlewareInterface
{
    private const OWN_MESSAGES = 'Nowo\\HttpLogBundle\\Message\\';

    private LoggerInterface $logger;

    public function __construct(
        private HttpLogContext $context,
        private bool $propagateTenant = true,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (str_starts_with($envelope->getMessage()::class, self::OWN_MESSAGES)) {
            return $stack->next()->handle($envelope, $stack);
        }

        if (!$envelope->last(ReceivedStamp::class) instanceof ReceivedStamp) {
            return $stack->next()->handle($this->stamped($envelope), $stack);
        }

        $stamp = $envelope->last(HttpLogContextStamp::class);
        $frame = $stamp instanceof HttpLogContextStamp
            ? new HttpLogFrame($stamp->tenantKey, $stamp->projectKey, $stamp->messageId)
            : new HttpLogFrame(messengerMessageId: $this->transportMessageId($envelope));

        return $this->context->runFrame($frame, static fn (): Envelope => $stack->next()->handle($envelope, $stack));
    }

    private function stamped(Envelope $envelope): Envelope
    {
        if ($envelope->last(HttpLogContextStamp::class) instanceof HttpLogContextStamp) {
            return $envelope;
        }

        try {
            $frame = $this->context->current();

            return $envelope->with(new HttpLogContextStamp(
                UsageCapture::newEventId(),
                $this->propagateTenant ? $frame->tenantKey : null,
                $this->propagateTenant ? $frame->projectKey : null,
            ));
        } catch (Throwable $exception) {
            $this->logger->warning('HTTP log context stamp not added.', ['exception_class' => $exception::class]);

            return $envelope;
        }
    }

    private function transportMessageId(Envelope $envelope): ?string
    {
        $id = $envelope->last(TransportMessageIdStamp::class)?->getId();

        return is_string($id) || is_int($id) ? (string) $id : null;
    }
}
