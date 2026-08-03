<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\MessageHandler;

use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Message\ExportHttpLogMessage;
use Nowo\HttpLogBundle\Service\ExportHttpLogService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ExportHttpLogHandler
{
    public function __construct(
        private readonly ExportHttpLogService $exportService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExportHttpLogMessage $message): void
    {
        $format = ExportFormat::tryFrom($message->format) ?? ExportFormat::Csv;
        $rows   = $this->exportService->exportToFile($message->criteria, $format, $message->outputPath);

        $this->logger->info('HTTP log export completed.', [
            'rows'       => $rows,
            'format'     => $format->value,
            'outputPath' => $message->outputPath,
        ]);
    }
}
