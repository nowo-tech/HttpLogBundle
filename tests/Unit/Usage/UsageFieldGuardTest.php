<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Usage;

use Nowo\HttpLogBundle\Enum\OutboundErrorKind;
use Nowo\HttpLogBundle\Usage\UsageFieldGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

use const INF;

final class UsageFieldGuardTest extends TestCase
{
    #[Test]
    public function tokensAreKeptOnlyWhenTheyMatchTheCharsetAndLength(): void
    {
        self::assertSame('org-1._:@x', UsageFieldGuard::token('org-1._:@x'));
        self::assertSame(str_repeat('a', 64), UsageFieldGuard::token(str_repeat('a', 64)));
        self::assertNull(UsageFieldGuard::token(str_repeat('a', 65)), 'never truncated');
        self::assertNull(UsageFieldGuard::token('Bearer test-token-placeholder'));
        self::assertNull(UsageFieldGuard::token(''));
        self::assertNull(UsageFieldGuard::token(42));
        self::assertNull(UsageFieldGuard::token("org\n"));
        self::assertSame('abc', UsageFieldGuard::token('abc', 3));
        self::assertNull(UsageFieldGuard::token('abcd', 3));
    }

    #[Test]
    public function providerAlsoAcceptsTheSchemePlus(): void
    {
        self::assertSame('resend+api', UsageFieldGuard::provider('resend+api'));
        self::assertNull(UsageFieldGuard::provider('a b'));
        self::assertNull(UsageFieldGuard::provider(str_repeat('a', 65)));
    }

    #[Test]
    public function channelMethodAndScheme(): void
    {
        self::assertSame('outbound', UsageFieldGuard::channel('outbound'));
        self::assertNull(UsageFieldGuard::channel('Outbound'));
        self::assertNull(UsageFieldGuard::channel(null));

        self::assertSame('PATCH', UsageFieldGuard::method('patch'));
        self::assertSame('OTHER', UsageFieldGuard::method('GET /x'));
        self::assertSame('OTHER', UsageFieldGuard::method(null));

        self::assertSame('resend+api', UsageFieldGuard::scheme('RESEND+API'));
        self::assertNull(UsageFieldGuard::scheme('1http'));
        self::assertNull(UsageFieldGuard::scheme(null));
    }

    #[Test]
    public function hostAndPath(): void
    {
        self::assertSame('api.example.com:8443', UsageFieldGuard::host('api.example.com:8443'));
        self::assertSame('[::1]:8080', UsageFieldGuard::host('[::1]:8080'));
        self::assertNull(UsageFieldGuard::host('user:pass@example.com'));
        self::assertNull(UsageFieldGuard::host('Example.com'));
        self::assertNull(UsageFieldGuard::host(str_repeat('a', 256)));
        self::assertNull(UsageFieldGuard::host(null));

        self::assertSame('/v2/{id}/{…}', UsageFieldGuard::path('/v2/{id}/{…}'));
        self::assertSame('/', UsageFieldGuard::path('/'));
        self::assertSame(UsageFieldGuard::COLLAPSED_PATH, UsageFieldGuard::path('v2'));
        self::assertSame(UsageFieldGuard::COLLAPSED_PATH, UsageFieldGuard::path('/a b'));
        self::assertSame(UsageFieldGuard::COLLAPSED_PATH, UsageFieldGuard::path('/' . str_repeat('a', 512)));
        self::assertSame(UsageFieldGuard::COLLAPSED_PATH, UsageFieldGuard::path(null));
    }

    #[Test]
    public function contentTypeLosesItsParameters(): void
    {
        self::assertSame('application/json', UsageFieldGuard::contentType('Application/JSON; charset=utf-8'));
        self::assertSame('text/html', UsageFieldGuard::contentType('text/html'));
        self::assertNull(UsageFieldGuard::contentType('not a type'));
        self::assertNull(UsageFieldGuard::contentType('a/' . str_repeat('b', 130)));
        self::assertNull(UsageFieldGuard::contentType(null));
    }

    #[Test]
    public function numbersAndKinds(): void
    {
        self::assertSame('timeout', UsageFieldGuard::errorKind(OutboundErrorKind::Timeout));
        self::assertSame('http_4xx', UsageFieldGuard::errorKind('http_4xx'));
        self::assertNull(UsageFieldGuard::errorKind('Idle timeout reached for "https://example.com"'));
        self::assertNull(UsageFieldGuard::errorKind(3));

        self::assertSame(204, UsageFieldGuard::statusCode(204));
        self::assertSame(204, UsageFieldGuard::statusCode('204'));
        self::assertSame(204, UsageFieldGuard::statusCode(204.0));
        self::assertNull(UsageFieldGuard::statusCode(0));
        self::assertNull(UsageFieldGuard::statusCode(1000));
        self::assertNull(UsageFieldGuard::statusCode(20.5));
        self::assertNull(UsageFieldGuard::statusCode('2e2'));
        self::assertNull(UsageFieldGuard::statusCode([]));

        self::assertSame(0, UsageFieldGuard::nonNegativeInt(0));
        self::assertNull(UsageFieldGuard::nonNegativeInt(-1));
        self::assertNull(UsageFieldGuard::nonNegativeInt(40000, 32767));

        self::assertSame(1.5, UsageFieldGuard::duration(1.5));
        self::assertSame(2.0, UsageFieldGuard::duration(2));
        self::assertNull(UsageFieldGuard::duration(-0.1));
        self::assertNull(UsageFieldGuard::duration(INF));
        self::assertNull(UsageFieldGuard::duration('1'));
    }

    #[Test]
    public function idsAreValidatedNotCut(): void
    {
        self::assertSame('0123456789abcdef0123456789abcdef', UsageFieldGuard::eventId('0123456789abcdef0123456789abcdef'));
        self::assertNull(UsageFieldGuard::eventId('client-chosen-id'));

        self::assertSame('42', UsageFieldGuard::messageId('42'));
        self::assertNull(UsageFieldGuard::messageId(str_repeat('a', 65)));

        self::assertSame('abc.123@mail.example', UsageFieldGuard::externalMessageId(' <abc.123@mail.example> '));
        self::assertSame('<abc', UsageFieldGuard::externalMessageId('<abc'));
        self::assertNull(UsageFieldGuard::externalMessageId('has space'));
        self::assertNull(UsageFieldGuard::externalMessageId(str_repeat('a', 256)));
        self::assertNull(UsageFieldGuard::externalMessageId(null));
    }
}
