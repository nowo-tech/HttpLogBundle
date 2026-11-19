<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\EventSubscriber;

use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Throwable;

use function is_string;
use function str_starts_with;

/**
 * 1.22 (REQ-RUNTIME-003 rule 6): a lock conflict on a route of the bundle (the admin delete, purge and export) answers
 * a conflict instead of a 500.
 *
 * A lock conflict is a deadlock, lock wait timeout or serialization failure anywhere down the chain, a
 * {@see TransientStorageFailure}, or a lost savepoint of unknown outcome. It answers with the status of the
 * {@see ConflictResponsePolicy} (**409** by default, 503 when the host configures it) and a {@see ConflictCode} in the
 * `X-Conflict-Code` header:
 * - `CONFLICT_001` with `Retry-After` when nothing of the request was committed;
 * - `CONFLICT_002` without `Retry-After` when part of it may be: a purge batch already deleted, or anything committed
 *   since the request started (transaction-doctrine's request mark, {@see ConflictCode::forRequest()}, when its bundle
 *   is registered with `commit_detection`; else the bundle's own mark on the {@see CommitLedger} that the
 *   commit-tracking middleware feeds), a committed `ConcurrencyConflict`, or a lost savepoint with no deadlock behind it.
 *
 * Unique-constraint violations, lost connections and any other storage error are not mapped: a real error. The admin
 * routes write no row with a unique key.
 *
 * The pages are rendered by the application's error page: the answer is an HttpException with a fixed, client-safe
 * message and the storage error as previous (for the logs; no SQL or value reaches the client).
 *
 * Only the bundle's controllers: the host's routes keep the host's error handling, and the capture of host requests
 * never throws into them. Stateless apart from the shared ledger, a monotonic counter read by difference only
 * (FrankenPHP worker mode, kernel reset off).
 */
final readonly class HttpLogConflictListener
{
    /** kernel.exception priority: after the application's own listeners, before the framework's error listener (-128). */
    public const PRIORITY = -64;

    /** kernel.request priority: before the router (32) and the firewall (8), so no controller wrote yet. */
    public const REQUEST_PRIORITY = 2048;

    /** Request attribute holding the bundle's own commit mark. */
    public const MARK_ATTRIBUTE = '_nowo_http_log_commit_mark';

    /** Message of a CONFLICT_001 answer (nothing committed). */
    public const RETRY_SAFE_MESSAGE = 'The HTTP log is being changed at the same time. Retry in a moment.';

    /** Message of a CONFLICT_002 answer (part of the request may be committed). */
    public const COMMITTED_MESSAGE = 'Part of the change may already be saved. Reload the page before trying again.';

    /** Controller namespace of the bundle. */
    private const CONTROLLER_PREFIX = 'Nowo\\HttpLogBundle\\Controller\\';

    private ConflictResponsePolicy $policy;

    private TransientFailureClassifier $classifier;

    /**
     * @param ConflictResponsePolicy|null $policy the conflict status and default Retry-After (null = 409, 1 s)
     * @param CommitLedger|null $ledger real commits counted by the commit-tracking middleware (null = none)
     */
    public function __construct(
        ?ConflictResponsePolicy $policy = null,
        private ?CommitLedger $ledger = null,
        private ?RequestStack $requestStack = null,
    ) {
        $this->policy     = $policy ?? new ConflictResponsePolicy();
        $this->classifier = new TransientFailureClassifier();
    }

    /**
     * Takes the bundle's commit mark on the main request.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($this->ledger instanceof CommitLedger && $event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::MARK_ATTRIBUTE, new CommitMark($this->ledger));
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$this->isBundleRoute($request)) {
            return;
        }

        $failure = $event->getThrowable();
        if (!$this->isLockConflict($failure)) {
            return;
        }

        $code = $this->code($request, $failure);
        $event->setThrowable($this->policy->httpExceptionFor(
            $code,
            $code->retrySafe() ? self::RETRY_SAFE_MESSAGE : self::COMMITTED_MESSAGE,
            $failure,
            $this->hint($failure),
        ));
    }

    private function isLockConflict(Throwable $failure): bool
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                return true;
            }
        }

        return $this->classifier->isTransient($failure) || $this->classifier->isCommitOutcomeUnknown($failure);
    }

    /**
     * CONFLICT_002 when transaction-doctrine's request mark, or the bundle's own one, saw a commit; else the failure decides.
     */
    private function code(Request $request, Throwable $failure): ConflictCode
    {
        $code = ConflictCode::forRequest($request, $failure);
        if ($code->retrySafe() && $this->ownMark($request)?->committedSince() === true) {
            return ConflictCode::Committed;
        }

        return $code;
    }

    private function ownMark(Request $request): ?CommitMark
    {
        $mark = $request->attributes->get(self::MARK_ATTRIBUTE) ?? $this->requestStack?->getMainRequest()?->attributes->get(self::MARK_ATTRIBUTE);

        return $mark instanceof CommitMark ? $mark : null;
    }

    /** The first Retry-After hint of the chain (a transient failure of a runner); null for none or zero. */
    private function hint(Throwable $failure): ?int
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof TransientStorageFailure) {
                $hint = $current->retryAfterSeconds();

                return $hint !== null && $hint > 0 ? $hint : null;
            }
        }

        return null;
    }

    /**
     * True when the request is handled by a controller of the bundle.
     */
    private function isBundleRoute(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');

        return is_string($controller) && str_starts_with($controller, self::CONTROLLER_PREFIX);
    }
}
