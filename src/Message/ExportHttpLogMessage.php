<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Message;

final readonly class ExportHttpLogMessage
{
    /**
     * @param array<string, mixed> $criteria
     */
    public function __construct(
        public array $criteria,
        public string $format,
        public string $outputPath,
    ) {
    }
}
