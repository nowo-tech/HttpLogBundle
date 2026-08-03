<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Message;

use Nowo\HttpLogBundle\Message\ExportHttpLogMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExportHttpLogMessageTest extends TestCase
{
    #[Test]
    public function constructorExposesReadonlyProperties(): void
    {
        $message = new ExportHttpLogMessage(['method' => 'GET'], 'csv', '/tmp/export.csv');

        self::assertSame(['method' => 'GET'], $message->criteria);
        self::assertSame('csv', $message->format);
        self::assertSame('/tmp/export.csv', $message->outputPath);
    }
}
