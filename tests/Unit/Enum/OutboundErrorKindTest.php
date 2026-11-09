<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Enum;

use Nowo\HttpLogBundle\Enum\OutboundErrorKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OutboundErrorKindTest extends TestCase
{
    #[Test]
    public function statusMapsToHttpKinds(): void
    {
        self::assertNull(OutboundErrorKind::fromStatus(null));
        self::assertNull(OutboundErrorKind::fromStatus(399));
        self::assertSame(OutboundErrorKind::Http4xx, OutboundErrorKind::fromStatus(400));
        self::assertSame(OutboundErrorKind::Http4xx, OutboundErrorKind::fromStatus(499));
        self::assertSame(OutboundErrorKind::Http5xx, OutboundErrorKind::fromStatus(503));
    }
}
