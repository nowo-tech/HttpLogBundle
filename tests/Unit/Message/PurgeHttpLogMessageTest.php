<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Message;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Message\PurgeHttpLogMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PurgeHttpLogMessageTest extends TestCase
{
    #[Test]
    public function constructorExposesReadonlyProperties(): void
    {
        $before  = new DateTimeImmutable('2026-08-03T10:00:00+00:00');
        $message = new PurgeHttpLogMessage($before, ['method' => 'DELETE']);

        self::assertSame($before, $message->before);
        self::assertSame(['method' => 'DELETE'], $message->criteria);
    }
}
