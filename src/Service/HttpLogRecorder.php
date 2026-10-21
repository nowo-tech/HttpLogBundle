<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Model\HttpLogCapture;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function array_keys;
use function is_string;

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
    ) {
    }

    public function record(Request $request, Response $response, float $durationMs, ?string $requestId): void
    {
        try {
            $capture = $this->buildCapture($request, $response, $durationMs, $requestId);

            if ($this->async) {
                $this->messageBus->dispatch(new PersistHttpLogMessage($capture->toArray()));

                return;
            }

            $this->persistCapture($capture);
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
     * Persists the capture and detaches the entry afterwards, so a long-lived EntityManager
     * (worker mode without kernel reset) does not keep one entry per logged request.
     * A manager closed by a failed flush is reset before the exception is rethrown.
     */
    public function persistCapture(HttpLogCapture $capture): void
    {
        $entityManager = $this->resolveEntityManager();
        $entry         = $this->createEntryFromCapture($capture);

        try {
            $entityManager->persist($entry);
            $entityManager->flush();
        } catch (Throwable $exception) {
            $this->resetClosedEntityManager($entityManager);

            throw $exception;
        } finally {
            if ($entityManager->isOpen() && $entityManager->contains($entry)) {
                $entityManager->detach($entry);
            }
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

    private function resolveEntityManager(): EntityManagerInterface
    {
        if (!$this->managerRegistry instanceof ManagerRegistry) {
            return $this->entityManager;
        }

        $manager = $this->managerRegistry->getManagerForClass(HttpLogEntry::class);
        if (!$manager instanceof EntityManagerInterface) {
            return $this->entityManager;
        }

        return $this->resetClosedEntityManager($manager) ?? $manager;
    }

    private function resetClosedEntityManager(EntityManagerInterface $manager): ?EntityManagerInterface
    {
        if ($manager->isOpen() || !$this->managerRegistry instanceof ManagerRegistry) {
            return null;
        }

        foreach (array_keys($this->managerRegistry->getManagerNames()) as $name) {
            if ($this->managerRegistry->getManager($name) !== $manager) {
                continue;
            }

            $this->logger->warning('Resetting closed EntityManager used for HTTP log entries.', ['manager' => $name]);
            $reset = $this->managerRegistry->resetManager($name);

            return $reset instanceof EntityManagerInterface ? $reset : null;
        }

        return null;
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
