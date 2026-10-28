<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\MessageHandler;

use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Message\ExportHttpLogMessage;
use Nowo\HttpLogBundle\Service\ExportFileStore;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExportHttpLogHandler
{
    public function __construct(
        private ExportHttpLogService $exportService,
        private LoggerInterface $logger,
        private ?ExportFileStore $exportStore = null,
    ) {
    }

    public function __invoke(ExportHttpLogMessage $message): void
    {
        $format = ExportFormat::tryFrom($message->format) ?? ExportFormat::Csv;
        $store  = $message->exportName !== null ? $this->exportStore : null;
        $path   = $store instanceof ExportFileStore ? $store->partialPath((string) $message->exportName) : $message->outputPath;
        $rows   = $this->exportService->exportToFile($message->criteria, $format, $path);

        if ($store instanceof ExportFileStore) {
            $store->complete((string) $message->exportName);
            $store->purgeExpired();
        }

        $this->logger->info('HTTP log export completed.', [
            'rows'       => $rows,
            'format'     => $format->value,
            'outputPath' => $path,
        ]);
    }
}
