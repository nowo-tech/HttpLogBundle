<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

use function array_filter;
use function array_values;

/** Keeps every log record (level, message, context) for assertions. */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function warnings(): array
    {
        return array_values(array_filter($this->records, static fn (array $r): bool => $r['level'] === 'warning'));
    }
}
