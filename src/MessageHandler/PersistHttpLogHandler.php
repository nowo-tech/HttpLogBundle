<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\MessageHandler;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Model\HttpLogCapture;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PersistHttpLogHandler
{
    public function __construct(
        private HttpLogEntryRepository $repository,
        private HttpLogRecorder $recorder,
        private LoggerInterface $logger,
        // HL-1: set by the extension when outbound or mailer capture is enabled.
        private ?UsageEntryWriter $usageWriter = null,
    ) {
    }

    public function __invoke(PersistHttpLogMessage $message): void
    {
        if (isset($message->payload[UsageCapture::PAYLOAD_MARKER])) {
            $this->persistUsage($message->payload);

            return;
        }

        $capture = HttpLogCapture::fromArray($message->payload);

        if ($capture->requestId !== null && $this->findExistingByRequestId($capture->requestId) instanceof HttpLogEntry) {
            return;
        }

        try {
            $this->recorder->persistCapture($capture);
        } catch (UniqueConstraintViolationException) {
            $this->logger->debug('HTTP log entry already persisted.', [
                'requestId' => $capture->requestId,
            ]);
        }
    }

    /**
     * Usage rows (outbound, mailer): one DBAL insert, deduplicated on the server-generated event id, so a redelivered
     * message writes nothing twice. A payload of a feature that is no longer enabled is dropped with a warning.
     *
     * @param array<string, mixed> $payload
     */
    private function persistUsage(array $payload): void
    {
        if (!$this->usageWriter instanceof UsageEntryWriter) {
            $this->logger->warning('HTTP log usage message dropped: no usage channel or rollup is enabled.');

            return;
        }

        try {
            $capture = UsageCapture::fromArray($payload);
        } catch (InvalidArgumentException) {
            $this->logger->warning('HTTP log usage message dropped: invalid payload.');

            return;
        }

        if (!$this->usageWriter->write($capture)) {
            $this->logger->debug('HTTP log usage entry already persisted.');
        }
    }

    private function findExistingByRequestId(string $requestId): ?HttpLogEntry
    {
        /** @var HttpLogEntry|null $entry */
        $entry = $this->repository->findOneBy(['requestId' => $requestId]);
        if ($entry instanceof HttpLogEntry) {
            // Avoid leaving the hydrated row in a long-lived identity map (worker mode).
            $this->repository->detachAll([$entry]);
        }

        return $entry;
    }
}
