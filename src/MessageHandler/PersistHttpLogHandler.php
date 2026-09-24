<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\MessageHandler;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Model\HttpLogCapture;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PersistHttpLogHandler
{
    public function __construct(
        private readonly HttpLogEntryRepository $repository,
        private readonly HttpLogRecorder $recorder,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PersistHttpLogMessage $message): void
    {
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
