<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\MessageHandler;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Message\PurgeHttpLogMessage;
use Nowo\HttpLogBundle\Service\PurgeHttpLogService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class PurgeHttpLogHandler
{
    public function __construct(
        private readonly PurgeHttpLogService $purgeService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeHttpLogMessage $message): void
    {
        if ($message->before instanceof DateTimeImmutable) {
            $purged = $this->purgeService->purgeOlderThan($message->before);
        } elseif ($message->criteria !== null) {
            $purged = $this->purgeService->purgeByCriteria($message->criteria);
        } else {
            $purged = $this->purgeService->purgeByRetention();
        }

        $this->logger->info('HTTP log purge completed.', ['purged' => $purged]);
    }
}
