<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Event\HttpLogEntryRecordedEvent;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Model\HttpLogCapture;
use Nowo\HttpLogBundle\Privacy\HttpLogErasedSubjects;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function bin2hex;
use function is_string;
use function random_bytes;

/**
 * Builds and persists HTTP log entries from request/response pairs.
 */
final readonly class HttpLogRecorder
{
    /**
     * @param array<string, mixed> $captureConfig
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private HttpLogRedactor $redactor,
        private CapturePolicy $capturePolicy,
        private ContentTypeClassifier $contentTypeClassifier,
        private bool $async,
        private array $captureConfig,
        private ?TokenStorageInterface $tokenStorage = null,
        private ?ManagerRegistry $managerRegistry = null,
        private ?Security $security = null,
        // HL-1: set while outbound / mailer capture is enabled, so this dispatch / write is never captured itself
        private ?HttpLogBuffer $buffer = null,
        // LY-L2: the request that erased this person's data is not written again at kernel.terminate
        private ?HttpLogErasedSubjects $erased = null,
        // REQ-ARCH-001 rule 4: HttpLogEntryRecordedEvent after a committed insert
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    public function record(Request $request, Response $response, float $durationMs, ?string $requestId): void
    {
        try {
            if ($this->erased?->dropsCurrentRequest() === true) {
                return;
            }
            $capture = $this->buildCapture($request, $response, $durationMs, $requestId);
            if ($this->erased?->matches($capture->userIdentifier, $capture->clientIp) === true) {
                return;
            }
            $write = function () use ($capture): void {
                if ($this->async) {
                    $this->messageBus->dispatch(new PersistHttpLogMessage($capture->toArray()));

                    return;
                }

                $this->persistCapture($capture);
            };

            $this->buffer instanceof HttpLogBuffer ? $this->buffer->suspended($write) : $write();
        } catch (Throwable $exception) {
            $this->logger->error('Failed to record HTTP log entry.', [
                'exception' => $exception,
                'path'      => $request->getPathInfo(),
            ]);
        }
    }

    private function buildCapture(Request $request, Response $response, float $durationMs, ?string $requestId): HttpLogCapture
    {
        $responseContentType = $response->headers->get('Content-Type');
        $bodyContentType     = $this->contentTypeClassifier->classify(
            $responseContentType,
            $this->safeResponseBody($response),
        );

        // Bodies are redacted in full BEFORE truncation: a truncated JSON document cannot be decoded,
        // so redacting afterwards would store its secrets verbatim.
        $requestBodyResult = $this->capturePolicy->evaluateRequestBody(
            $this->capturePolicy->shouldCaptureRequestBody()
                ? $this->redactor->redactBody($request->getContent(false), $request->headers->get('Content-Type'))
                : null,
        );
        $responseBodyResult = $this->capturePolicy->evaluateResponseBody(
            $this->capturePolicy->shouldCaptureResponseBody($bodyContentType)
                ? $this->redactor->redactBody($this->safeResponseBody($response), $responseContentType)
                : null,
            $bodyContentType,
        );

        $requestHeaders = ($this->captureConfig['request_headers'] ?? true)
            ? $this->redactor->redactHeaders($request->headers->all())
            : null;
        $responseHeaders = ($this->captureConfig['response_headers'] ?? true)
            ? $this->redactor->redactHeaders($response->headers->all())
            : null;

        $queryParams = $this->redactor->redactQueryParams($request->query->all());

        return new HttpLogCapture(
            requestId: $requestId,
            method: $request->getMethod(),
            scheme: $request->getScheme(),
            // Host with its port when the request used a non-default one (e.g. 127.0.0.1:8000).
            host: $request->getHttpHost(),
            path: $request->getPathInfo(),
            routeName: is_string($request->attributes->get('_route')) ? $request->attributes->get('_route') : null,
            queryParams: $queryParams,
            statusCode: $response->getStatusCode(),
            clientIp: ($this->captureConfig['client_ip'] ?? true) ? $request->getClientIp() : null,
            contentType: $responseContentType,
            bodyContentType: $bodyContentType->value,
            durationMs: $durationMs,
            userIdentifier: ($this->captureConfig['user'] ?? true) ? $this->resolveUserIdentifier($request) : null,
            requestHeaders: $requestHeaders,
            responseHeaders: $responseHeaders,
            requestBody: $requestBodyResult['body'],
            responseBody: $responseBodyResult['body'],
            requestBodyTruncated: $requestBodyResult['truncated'],
            responseBodyTruncated: $responseBodyResult['truncated'],
            responseBodyStored: $responseBodyResult['stored'],
            createdAt: DateTimeImmutable::createFromInterface($this->clock->now()),
        );
    }

    /**
     * Writes the capture with one DBAL `INSERT` on the connection of the entry's EntityManager.
     *
     * Never `persist()` / `flush()`: with `async: false` (or a sync transport) this runs inside the host request at
     * `kernel.terminate`, and a flush of the shared manager would also commit the host's pending changes (for example
     * an entity edited by a form that failed validation). No entity is kept in the identity map (worker safe), and a
     * failed insert does not close the manager. Inside an open host transaction the insert runs in a savepoint. Columns, types and the table name come from the class metadata, so
     * the configured prefixes and the optional usage columns apply.
     */
    public function persistCapture(HttpLogCapture $capture): void
    {
        $entityManager = $this->resolveEntityManager();
        $metadata      = $entityManager->getClassMetadata(HttpLogEntry::class);
        $entry         = $this->createEntryFromCapture($capture);
        $idGenerator   = $metadata->idGenerator;

        $data  = [];
        $types = [];
        foreach ($metadata->getFieldNames() as $field) {
            $isIdentifier = $metadata->isIdentifier($field);
            if ($isIdentifier && $idGenerator->isPostInsertGenerator()) {
                continue;
            }

            $column = $metadata->getColumnName($field);
            // SEQUENCE (or another pre-insert generator) chosen by the host's identity_generation_preferences
            $data[$column]  = $isIdentifier ? $idGenerator->generateId($entityManager, $entry) : $metadata->getFieldValue($entry, $field);
            $types[$column] = $metadata->getTypeOfField($field) ?? Types::STRING;
        }

        $connection = $entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            $connection->insert($metadata->getTableName(), $data, $types);
            $this->notifyRecorded($capture);

            return;
        }

        // Inside a transaction the host left open, the insert runs in a savepoint, so a failed insert (request id race,
        // value too long) rolls back only itself: a plain failed statement would abort the host's whole transaction on
        // PostgreSQL (SQLSTATE 25P02), which the ORM flush (a nested transaction) never did. Explicit savepoint calls,
        // because DBAL 3 nests transactional() without a savepoint unless the host enabled them.
        // A name unique per call: on MySQL / MariaDB a second SAVEPOINT of the same name replaces the first, so a fixed
        // name could replace a host savepoint that happens to share it.
        $savepoint = 'nowo_http_log_insert_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($savepoint);
        try {
            $connection->insert($metadata->getTableName(), $data, $types);
        } catch (Throwable $exception) {
            try {
                $connection->rollbackSavepoint($savepoint);
            } catch (Throwable) {
                // A deadlock or lock wait timeout may already have rolled back the whole transaction (and the savepoint
                // with it): the original error is the one to report.
            }

            throw $exception;
        }
        $connection->releaseSavepoint($savepoint);
    }

    /**
     * {@see HttpLogEntryRecordedEvent} for a web entry written outside any open transaction (autocommit: committed).
     * Inside a host transaction the row may still roll back, so nothing is dispatched. A failing listener is logged.
     */
    private function notifyRecorded(HttpLogCapture $capture): void
    {
        if (!$this->events instanceof EventDispatcherInterface) {
            return;
        }

        try {
            $this->events->dispatch(new HttpLogEntryRecordedEvent(
                channel: HttpLogEntryRecordedEvent::CHANNEL_WEB,
                requestId: $capture->requestId,
                method: $capture->method,
                routeName: $capture->routeName,
                statusCode: $capture->statusCode,
                durationMs: $capture->durationMs,
                recordedAt: $capture->createdAt,
            ));
        } catch (Throwable $exception) {
            $this->logger->warning('HTTP log entry-recorded listener failed.', ['exception_class' => $exception::class]);
        }
    }

    public function createEntryFromCapture(HttpLogCapture $capture): HttpLogEntry
    {
        $entry = new HttpLogEntry();
        $entry
            ->setRequestId($capture->requestId)
            ->setMethod($capture->method)
            ->setScheme($capture->scheme)
            ->setHost($capture->host)
            ->setPath($capture->path)
            ->setRouteName($capture->routeName)
            ->setQueryParams($capture->queryParams)
            ->setStatusCode($capture->statusCode)
            ->setClientIp($capture->clientIp)
            ->setContentType($capture->contentType)
            ->setBodyContentType($capture->bodyContentType)
            ->setDurationMs($capture->durationMs)
            ->setUserIdentifier($capture->userIdentifier)
            ->setRequestHeaders($capture->requestHeaders)
            ->setResponseHeaders($capture->responseHeaders)
            ->setRequestBody($capture->requestBody)
            ->setResponseBody($capture->responseBody)
            ->setRequestBodyTruncated($capture->requestBodyTruncated)
            ->setResponseBodyTruncated($capture->responseBodyTruncated)
            ->setResponseBodyStored($capture->responseBodyStored)
            ->setCreatedAt($capture->createdAt);

        return $entry;
    }

    /**
     * The manager is only used for its metadata and connection: a closed manager still has both, so it is never reset
     * here (it may be the host's default manager).
     */
    private function resolveEntityManager(): EntityManagerInterface
    {
        if (!$this->managerRegistry instanceof ManagerRegistry) {
            return $this->entityManager;
        }

        $manager = $this->managerRegistry->getManagerForClass(HttpLogEntry::class);

        return $manager instanceof EntityManagerInterface ? $manager : $this->entityManager;
    }

    /**
     * The token storage is not reset between requests when the kernel is reused (worker mode
     * without services_resetter), so the token is only trusted when a firewall with security
     * enabled handled the current request.
     */
    private function resolveUserIdentifier(Request $request): ?string
    {
        if (!$this->tokenStorage instanceof TokenStorageInterface || !$this->isHandledBySecuredFirewall($request)) {
            return null;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TokenInterface) {
            return null;
        }

        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }

        return $user->getUserIdentifier();
    }

    private function isHandledBySecuredFirewall(Request $request): bool
    {
        if ($this->security instanceof Security) {
            return $this->security->getFirewallConfig($request)?->isSecurityEnabled() ?? false;
        }

        return $request->attributes->has('_firewall_context');
    }

    private function safeResponseBody(Response $response): ?string
    {
        $content = $response->getContent();
        if ($content === false) {
            return null;
        }

        return $content;
    }
}
