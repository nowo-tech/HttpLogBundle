<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Enum;

use Nowo\HttpLogBundle\Enum\ExportFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExportFormatTest extends TestCase
{
    #[Test]
    public function casesAreNotEmpty(): void
    {
        self::assertNotEmpty(ExportFormat::cases());
    }

    #[Test]
    public function casesHaveExpectedStringValues(): void
    {
        self::assertSame('csv', ExportFormat::Csv->value);
        self::assertSame('json', ExportFormat::Json->value);
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(ExportFormat::tryFrom('xml'));
    }
}
