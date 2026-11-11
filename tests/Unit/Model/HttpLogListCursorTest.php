<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Model;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Model\HttpLogListCursor;
use Nowo\HttpLogBundle\Model\HttpLogListRow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** PERF-1147-02: the `?cursor=` of the admin list round-trips and never trusts a malformed value. */
final class HttpLogListCursorTest extends TestCase
{
    #[Test]
    public function aPageCursorRoundTripsThroughItsTextForm(): void
    {
        $cursor = HttpLogListCursor::fromRows(
            3,
            new HttpLogListRow(42, new DateTimeImmutable('2026-08-03 10:01:59'), 'GET', '/a'),
            new HttpLogListRow(40, new DateTimeImmutable('2026-08-03 10:01:58'), 'GET', '/b'),
            new HttpLogListRow(7, new DateTimeImmutable('2026-08-02 23:00:00'), 'GET', '/c'),
        );

        self::assertNotNull($cursor);
        self::assertSame('3.20260803100159.42.20260802230000.7', $cursor->toString());
        self::assertEquals($cursor, HttpLogListCursor::fromString($cursor->toString()));
    }

    #[Test]
    public function anEmptyPageHasNoCursor(): void
    {
        self::assertNull(HttpLogListCursor::fromRows(1));
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalid(): iterable
    {
        yield 'absent' => [null];
        yield 'array' => [['1.20260803100159.42.20260802230000.7']];
        yield 'empty' => [''];
        yield 'page zero' => ['0.20260803100159.42.20260802230000.7'];
        yield 'id zero' => ['1.20260803100159.0.20260802230000.7'];
        yield 'missing part' => ['1.20260803100159.42.20260802230000'];
        yield 'month 13' => ['1.20261303100159.42.20260802230000.7'];
        yield 'hour 24' => ['1.20260803240000.42.20260802230000.7'];
        yield 'trailing newline' => ["1.20260803100159.42.20260802230000.7\n"];
        yield 'sql' => ["1.20260803100159.42.20260802230000.7' OR 1=1"];
        yield 'huge id' => ['1.20260803100159.1234567890123456789.20260802230000.7'];
    }

    #[Test]
    #[DataProvider('invalid')]
    public function aMalformedValueIsIgnored(mixed $value): void
    {
        self::assertNull(HttpLogListCursor::fromString($value));
    }
}
