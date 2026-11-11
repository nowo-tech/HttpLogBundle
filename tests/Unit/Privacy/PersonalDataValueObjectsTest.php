<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Privacy;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Privacy\HttpLogClientIpWindow;
use Nowo\HttpLogBundle\Privacy\HttpLogErasureResult;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataErasedEvent;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * GDPR ports (1.18): the subject keeps the value exactly as given and rejects what the columns cannot hold; result,
 * event and IP window are plain values.
 */
final class PersonalDataValueObjectsTest extends TestCase
{
    #[Test]
    public function subjectsKeepTheValueExactly(): void
    {
        $user = HttpLogPersonalDataSubject::fromUserIdentifier(' Ana@Example.test ');
        $ip   = HttpLogPersonalDataSubject::fromClientIp('FE80::A');

        self::assertSame(['type' => 'user_identifier', 'value' => ' Ana@Example.test '], $user->toArray());
        self::assertFalse($user->isClientIp());
        self::assertSame(['type' => 'client_ip', 'value' => 'FE80::A'], $ip->toArray());
        self::assertTrue($ip->isClientIp());
        self::assertTrue($user->equals(HttpLogPersonalDataSubject::fromUserIdentifier(' Ana@Example.test ')));
        self::assertFalse($user->equals(HttpLogPersonalDataSubject::fromUserIdentifier('ana@example.test')));
        self::assertFalse(HttpLogPersonalDataSubject::fromClientIp('a')->equals(HttpLogPersonalDataSubject::fromUserIdentifier('a')));
        self::assertSame(255, mb_strlen(HttpLogPersonalDataSubject::fromUserIdentifier(str_repeat('é', 255))->value), 'the cap counts characters');
    }

    /** @return iterable<string, array{0: 'ip'|'user', 1: string, 2: string}> */
    public static function invalidSubjects(): iterable
    {
        yield 'empty identifier' => ['user', '', 'The user identifier must not be empty.'];
        yield 'NUL identifier' => ['user', "ana\0x", 'The user identifier must not contain a NUL byte.'];
        yield 'long identifier' => ['user', str_repeat('a', 256), 'The user identifier must not be longer than 255 characters.'];
        yield 'empty IP' => ['ip', '', 'The client IP must not be empty.'];
        yield 'long IP' => ['ip', str_repeat('1', 46), 'The client IP must not be longer than 45 characters.'];
    }

    #[Test]
    #[DataProvider('invalidSubjects')]
    public function invalidSubjectsAreRejected(string $type, string $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $type === 'ip' ? HttpLogPersonalDataSubject::fromClientIp($value) : HttpLogPersonalDataSubject::fromUserIdentifier($value);
    }

    #[Test]
    public function resultAndEventCount(): void
    {
        $result = new HttpLogErasureResult(4);
        $event  = new HttpLogPersonalDataErasedEvent('user_identifier', 'ana', ['entries' => 4, 'other' => 1]);

        self::assertSame(['entries' => 4], $result->toArray());
        self::assertSame(4, $result->total());
        self::assertSame(0, (new HttpLogErasureResult())->total());
        self::assertSame(5, $event->total());
    }

    #[Test]
    public function clientIpWindows(): void
    {
        $window = new HttpLogClientIpWindow('10.0.0.1', new DateTimeImmutable('2026-01-01T10:00:00+00:00'), new DateTimeImmutable('2026-01-02T10:00:00+00:00'));

        self::assertSame(['client_ip' => '10.0.0.1', 'first_seen_at' => '2026-01-01T10:00:00+00:00', 'last_seen_at' => '2026-01-02T10:00:00+00:00'], $window->toArray());
    }

    #[Test]
    public function aWindowNeedsAnAddress(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpLogClientIpWindow('', new DateTimeImmutable(), new DateTimeImmutable());
    }

    #[Test]
    public function aWindowCannotEndBeforeItStarts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpLogClientIpWindow('10.0.0.1', new DateTimeImmutable('2026-01-02'), new DateTimeImmutable('2026-01-01'));
    }
}
