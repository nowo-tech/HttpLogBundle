<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Context\HttpLogFrame;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\LazyResponseException;
use Throwable;

use function is_int;

/**
 * Request-side half of the named channels (HL-1 §6.3, §6.4), registered only while a named channel or `rollup.web` is
 * configured:
 *
 * - just before the controller runs, resolves the request context of a named-channel request (when that cannot start a
 *   lazy firewall's authentication) and pushes its tenant / project as the request's {@see HttpLogContext} frame, so
 *   outbound calls and mails made while serving it inherit them; the frame is popped at `kernel.terminate`, after
 *   the request was recorded;
 * - marks a request for which the kernel handled an exception (its row gets the error kind `exception`).
 *
 * Recording itself happens in the existing {@see HttpLogSubscriber}.
 */
final readonly class InboundRequestSubscriber implements EventSubscriberInterface
{
    /** Request attribute holding the frame depth to restore at terminate. */
    public const FRAME_ATTRIBUTE = '_nowo_http_log_frame_depth';

    private LoggerInterface $logger;

    public function __construct(
        private InboundRequestRecorder $recorder,
        private HttpLogRequestContextResolution $resolution,
        private HttpLogContext $context,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // after the access checks (IsGranted runs at 20), right before the controller
            KernelEvents::CONTROLLER_ARGUMENTS => ['onControllerArguments', -1024],
            // before the listeners that turn the exception into a response and stop propagation
            KernelEvents::EXCEPTION => ['onException', 4096],
            // after HttpLogSubscriber (-1024) recorded the request, before the usage buffer flush (-2048)
            KernelEvents::TERMINATE => ['onTerminate', -1536],
        ];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->attributes->has(self::FRAME_ATTRIBUTE)) {
            return;
        }

        try {
            $channel = $this->recorder->match($request);
            if (!$channel instanceof InboundChannel || !$this->resolution->canResolveEarly()) {
                return;
            }

            $resolved = $this->resolution->resolveEarly($request, $channel->name);
            if ($resolved->tenantKey === null && $resolved->projectKey === null) {
                return;
            }

            // @igor-ignore - the request frame is popped at kernel.terminate and HttpLogContext is reset by kernel.reset
            $depth = $this->context->push(new HttpLogFrame($resolved->tenantKey, $resolved->projectKey, $this->context->current()->messengerMessageId));
            $request->attributes->set(self::FRAME_ATTRIBUTE, $depth);
        } catch (LazyResponseException $exception) {
            // canResolveEarly() never reads a pending lazy token; if one is started anyway, its response must win
            throw $exception;
        } catch (Throwable $exception) {
            $this->logger->warning('HTTP log request context dropped.', ['exception_class' => $exception::class]);
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(InboundRequestRecorder::EXCEPTION_ATTRIBUTE, true);
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $depth = $event->getRequest()->attributes->get(self::FRAME_ATTRIBUTE);
        if (is_int($depth)) {
            // @igor-ignore - pops the frame pushed for this request
            $this->context->restore($depth);
            $event->getRequest()->attributes->remove(self::FRAME_ATTRIBUTE);
        }
    }
}
