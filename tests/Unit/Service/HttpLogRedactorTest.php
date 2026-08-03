<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Service;

use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use PHPUnit\Framework\TestCase;

use const JSON_THROW_ON_ERROR;

final class HttpLogRedactorTest extends TestCase
{
    private HttpLogRedactor $redactor;

    protected function setUp(): void
    {
        $this->redactor = new HttpLogRedactor(
            ['Authorization', 'Cookie'],
            ['password', 'token'],
            ['*.password', '*.token', 'secret'],
        );
    }

    public function testRedactsHeadersCaseInsensitively(): void
    {
        $result = $this->redactor->redactHeaders([
            'Authorization' => 'Bearer secret',
            'cookie'        => 'session=abc',
            'Accept'        => 'application/json',
        ]);

        self::assertSame('[REDACTED]', $result['Authorization']);
        self::assertSame('[REDACTED]', $result['cookie']);
        self::assertSame('application/json', $result['Accept']);
    }

    public function testRedactsQueryParamsCaseInsensitively(): void
    {
        $result = $this->redactor->redactQueryParams([
            'password' => 'hunter2',
            'TOKEN'    => 'abc',
            'page'     => '1',
        ]);

        self::assertSame('[REDACTED]', $result['password']);
        self::assertSame('[REDACTED]', $result['TOKEN']);
        self::assertSame('1', $result['page']);
    }

    public function testRedactsJsonBodyWithWildcardAndExactPaths(): void
    {
        $body = json_encode([
            'user'   => ['password' => 'secret', 'name' => 'Ada'],
            'token'  => 'jwt',
            'secret' => 'value',
            'nested' => ['token' => 'nested-jwt'],
        ], JSON_THROW_ON_ERROR);

        $redacted = $this->redactor->redactJsonBody($body);
        self::assertIsString($redacted);

        $decoded = json_decode($redacted, true);
        self::assertSame('[REDACTED]', $decoded['user']['password']);
        self::assertSame('Ada', $decoded['user']['name']);
        self::assertSame('[REDACTED]', $decoded['token']);
        self::assertSame('[REDACTED]', $decoded['secret']);
        self::assertSame('[REDACTED]', $decoded['nested']['token']);
    }

    public function testLeavesNonJsonBodyUntouched(): void
    {
        self::assertSame('plain text', $this->redactor->redactJsonBody('plain text'));
    }

    public function testSkipsRedactionWhenJsonPathsEmpty(): void
    {
        $redactor = new HttpLogRedactor([], [], []);
        $body     = '{"password":"x"}';

        self::assertSame($body, $redactor->redactJsonBody($body));
    }

    public function testReturnsNullOrEmptyBodyUnchanged(): void
    {
        self::assertNull($this->redactor->redactJsonBody(null));
        self::assertSame('', $this->redactor->redactJsonBody(''));
    }
}
