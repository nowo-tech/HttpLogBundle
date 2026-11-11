<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\Service\ResetInterface;

use function count;

/**
 * Request-scoped skip list of the subjects erased during the current request, command or message (audit LY-L2).
 *
 * The request that performs an erasure is recorded at `kernel.terminate`, and outbound / mailer captures wait in the
 * usage buffer until the same boundary: both run after the erasure and would write the person's identifier or IP
 * again. The erasers remember every erased value here; the web recorder, the named-channel recorder and the usage
 * buffer drop a capture whose user identifier or client IP is one of them, and the web / named-channel capture of the
 * current request when a self-service erasure asked for it ({@see dropCurrentRequest()}).
 *
 * Worker mode (FrankenPHP, kernel reset false): the list lives for one boundary only. It is cleared at the start of
 * every main request and after the last capture of each boundary (`kernel.terminate`, `console.terminate`, a worker
 * message handled or failed; after the buffer flush at -2048 and the web capture at -1024), and on `kernel.reset`.
 *
 * @internal not a public API; use {@see HttpLogPersonalDataErasureInterface}
 */
final class HttpLogErasedSubjects implements EventSubscriberInterface, ResetInterface
{
    /** Values kept at most per boundary; beyond it every capture of the boundary is dropped (fail closed). */
    public const CAPACITY = 10000;

    /** After every capture written at the boundary. */
    private const AFTER_CAPTURES = -4096;

    /** `ConsoleEvents::TERMINATE` (symfony/console is optional). */
    private const CONSOLE_TERMINATE = 'console.terminate';

    /** @var array<string, true> */
    private array $userIdentifiers = [];

    /** @var array<string, true> */
    private array $clientIps = [];

    private bool $dropCurrentRequest = false;

    private bool $overflow = false;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST            => ['onRequest', 4096],
            KernelEvents::TERMINATE          => ['reset', self::AFTER_CAPTURES],
            self::CONSOLE_TERMINATE          => ['reset', self::AFTER_CAPTURES],
            WorkerMessageHandledEvent::class => ['reset', self::AFTER_CAPTURES],
            WorkerMessageFailedEvent::class  => ['reset', self::AFTER_CAPTURES],
        ];
    }

    /** A new main request starts a new boundary (nothing of the previous request survives, even without terminate). */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->reset();
        }
    }

    public function remember(HttpLogPersonalDataSubject $subject): void
    {
        if (count($this->userIdentifiers) + count($this->clientIps) >= self::CAPACITY) {
            $this->overflow = true;

            return;
        }
        if ($subject->isClientIp()) {
            $this->clientIps[$subject->value] = true;

            return;
        }
        $this->userIdentifiers[$subject->value] = true;
    }

    /** The web / named-channel capture of the current request is dropped (self-service erasure). */
    public function dropCurrentRequest(): void
    {
        $this->dropCurrentRequest = true;
    }

    public function dropsCurrentRequest(): bool
    {
        return $this->dropCurrentRequest || $this->overflow;
    }

    /** True when a capture with these values must not be written (exact, byte-for-byte match). */
    public function matches(?string $userIdentifier, ?string $clientIp): bool
    {
        if ($this->overflow) {
            return true;
        }

        return ($userIdentifier !== null && isset($this->userIdentifiers[$userIdentifier]))
            || ($clientIp !== null && isset($this->clientIps[$clientIp]));
    }

    public function isEmpty(): bool
    {
        return $this->userIdentifiers === [] && $this->clientIps === [] && !$this->dropCurrentRequest && !$this->overflow;
    }

    public function reset(): void
    {
        $this->userIdentifiers    = [];
        $this->clientIps          = [];
        $this->dropCurrentRequest = false;
        $this->overflow           = false;
    }
}
