<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Enum;

use Nowo\HttpLogBundle\Enum\CssFramework;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CssFrameworkTest extends TestCase
{
    #[Test]
    public function casesAreNotEmpty(): void
    {
        self::assertNotEmpty(CssFramework::cases());
    }

    #[Test]
    public function casesHaveExpectedStringValues(): void
    {
        self::assertSame('bootstrap5', CssFramework::Bootstrap5->value);
        self::assertSame('tailwind', CssFramework::Tailwind->value);
        self::assertSame('foundation', CssFramework::Foundation->value);
        self::assertSame('custom', CssFramework::Custom->value);
    }

    #[Test]
    public function canBeCreatedFromValue(): void
    {
        self::assertSame(CssFramework::Tailwind, CssFramework::from('tailwind'));
    }
}
