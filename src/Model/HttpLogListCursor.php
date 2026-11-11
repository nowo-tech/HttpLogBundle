<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Model;

use DateTimeImmutable;

use function array_key_last;
use function is_string;
use function preg_match;
use function sprintf;

/**
 * Keyset position of a rendered admin list page (PERF-1147-02): the page number and the `(created_at, id)` keys of
 * its first (newest) and last (oldest) rows. The page links carry it as `?cursor=` next to `?page=`, so the next page
 * reads the rows older than `last` and the previous page the rows newer than `first` through the `created_at` index,
 * instead of an `OFFSET` from the newest row. A link to a page further away skips only the pages in between.
 *
 * Text form: `<page>.<first YmdHis>.<first id>.<last YmdHis>.<last id>` (wall-clock time of the stored DATETIME, no
 * time zone conversion). It only selects a position inside the rows the filters and the channel access already allow;
 * an invalid or stale cursor is ignored (the page is then read by offset).
 */
final readonly class HttpLogListCursor
{
    /** Query parameter of the admin list. */
    public const PARAM = 'cursor';

    private const TIME_FORMAT = 'YmdHis';

    private const PATTERN = '/^([1-9]\d{0,5})\.(\d{14})\.([1-9]\d{0,17})\.(\d{14})\.([1-9]\d{0,17})$/D';

    public function __construct(
        public int $page,
        public DateTimeImmutable $firstCreatedAt,
        public int $firstId,
        public DateTimeImmutable $lastCreatedAt,
        public int $lastId,
    ) {
    }

    /** Cursor of a rendered page; null when the page has no rows. */
    public static function fromRows(int $page, HttpLogListRow ...$rows): ?self
    {
        if ($rows === []) {
            return null;
        }
        $first = $rows[0];
        $last  = $rows[array_key_last($rows)];

        return new self($page, $first->createdAt, $first->id, $last->createdAt, $last->id);
    }

    /** Parsed `?cursor=` value; null when absent or malformed (never an exception: it comes from the URL). */
    public static function fromString(mixed $value): ?self
    {
        if (!is_string($value) || preg_match(self::PATTERN, $value, $match) !== 1) {
            return null;
        }
        $first = self::time($match[2]);
        $last  = self::time($match[4]);
        if (!$first instanceof DateTimeImmutable || !$last instanceof DateTimeImmutable) {
            return null;
        }

        return new self((int) $match[1], $first, (int) $match[3], $last, (int) $match[5]);
    }

    public function toString(): string
    {
        return sprintf(
            '%d.%s.%d.%s.%d',
            $this->page,
            $this->firstCreatedAt->format(self::TIME_FORMAT),
            $this->firstId,
            $this->lastCreatedAt->format(self::TIME_FORMAT),
            $this->lastId,
        );
    }

    /** Strict wall-clock time: rejects overflowing values such as month 13 (createFromFormat() would roll them over). */
    private static function time(string $value): ?DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('!' . self::TIME_FORMAT, $value);

        return $time instanceof DateTimeImmutable && $time->format(self::TIME_FORMAT) === $value ? $time : null;
    }
}
