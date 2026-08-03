<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Enum;

use Nowo\HttpLogBundle\Enum\BodyContentType;
use PHPUnit\Framework\TestCase;

final class BodyContentTypeTest extends TestCase
{
    public function testCasesHaveExpectedStringValues(): void
    {
        self::assertSame('html', BodyContentType::Html->value);
        self::assertSame('json', BodyContentType::Json->value);
        self::assertSame('soap', BodyContentType::Soap->value);
        self::assertSame('xml', BodyContentType::Xml->value);
        self::assertSame('text', BodyContentType::Text->value);
        self::assertSame('binary', BodyContentType::Binary->value);
        self::assertSame('other', BodyContentType::Other->value);
    }

    public function testCanBeCreatedFromValue(): void
    {
        self::assertSame(BodyContentType::Json, BodyContentType::from('json'));
    }

    public function testAllCasesAreListed(): void
    {
        self::assertCount(7, BodyContentType::cases());
    }
}
