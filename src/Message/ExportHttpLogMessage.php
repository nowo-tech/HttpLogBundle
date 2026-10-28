<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Message;

use Nowo\HttpLogBundle\Service\ExportFileStore;

final readonly class ExportHttpLogMessage
{
    /**
     * @param array<string, mixed> $criteria
     * @param string|null $exportName name reserved in {@see ExportFileStore} (admin
     *                                exports): the handler writes to the private store and ignores $outputPath
     */
    public function __construct(
        public array $criteria,
        public string $format,
        public string $outputPath,
        public ?string $exportName = null,
    ) {
    }
}
