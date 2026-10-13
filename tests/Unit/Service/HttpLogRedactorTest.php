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

    public function testRedactBodyRedactsJsonByContentType(): void
    {
        $redacted = $this->redactor->redactBody('{"user":{"password":"x","name":"Ada"}}', 'application/json; charset=utf-8');

        self::assertSame('{"user":{"password":"[REDACTED]","name":"Ada"}}', $redacted);
    }

    public function testRedactBodyRedactsJsonLookingBodyWithoutContentType(): void
    {
        self::assertSame('{"token":"[REDACTED]"}', $this->redactor->redactBody(' {"token":"jwt"}', null));
    }

    public function testRedactBodyRedactsVendorJsonMimeType(): void
    {
        self::assertSame('{"token":"[REDACTED]"}', $this->redactor->redactBody('{"token":"jwt"}', 'application/problem+json'));
    }

    public function testRedactBodyReturnsPlaceholderForUnparseableJson(): void
    {
        self::assertSame(
            HttpLogRedactor::UNPARSEABLE_PLACEHOLDER,
            $this->redactor->redactBody('{"password":"hunter2","pad":"xx', 'application/json'),
        );
        self::assertSame(
            HttpLogRedactor::UNPARSEABLE_PLACEHOLDER,
            $this->redactor->redactBody('{"password":"hunter2"', null),
        );
    }

    public function testRedactBodyKeepsScalarJsonUnchanged(): void
    {
        self::assertSame('"plain"', $this->redactor->redactBody('"plain"', 'application/json'));
    }

    public function testRedactBodyLeavesNonStructuredBodiesUntouched(): void
    {
        self::assertSame('<p>password=x</p>', $this->redactor->redactBody('<p>password=x</p>', 'text/html'));
        self::assertNull($this->redactor->redactBody(null, 'application/json'));
        self::assertSame('', $this->redactor->redactBody('', 'application/json'));
    }

    public function testRedactBodySkipsEverythingWhenNoKeysConfigured(): void
    {
        $redactor = new HttpLogRedactor([], [], []);

        self::assertSame('password=x', $redactor->redactBody('password=x', 'application/x-www-form-urlencoded'));
        self::assertSame('{"password":', $redactor->redactBody('{"password":', 'application/json'));
    }

    public function testRedactBodyKeepsJsonWhenOnlyQueryKeysConfigured(): void
    {
        $redactor = new HttpLogRedactor([], ['password'], []);

        self::assertSame('{"password":"x"}', $redactor->redactBody('{"password":"x"}', 'application/json'));
        self::assertSame('password=%5BREDACTED%5D', $redactor->redactBody('password=x', 'application/x-www-form-urlencoded'));
    }

    public function testRedactBodyRedactsFormUrlEncodedFields(): void
    {
        $redactor = new HttpLogRedactor([], ['_password', '_csrf_token'], ['*.token']);

        self::assertSame(
            '_username=ada&_password=%5BREDACTED%5D&login%5Btoken%5D=%5BREDACTED%5D&_csrf_token=%5BREDACTED%5D&flag',
            $redactor->redactBody(
                '_username=ada&_password=hunter2&login%5Btoken%5D=abc&_csrf_token=xyz&flag',
                'application/x-www-form-urlencoded; charset=UTF-8',
            ),
        );
    }

    public function testRedactBodyRedactsMultipartTextFieldsButNotFiles(): void
    {
        $body = "--XyZ\r\n"
            . "Content-Disposition: form-data; name=\"user[password]\"\r\n\r\n"
            . "hunter2\r\n"
            . "--XyZ\r\n"
            . "Content-Disposition: form-data; name=\"name\"\r\n\r\n"
            . "Ada\r\n"
            . "--XyZ\r\n"
            . "Content-Disposition: form-data; name=\"token\"; filename=\"token.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\n"
            . "file-content\r\n"
            . "--XyZ--\r\n";

        $redacted = $this->redactor->redactBody($body, 'multipart/form-data; boundary="XyZ"');

        self::assertIsString($redacted);
        self::assertStringNotContainsString('hunter2', $redacted);
        self::assertStringContainsString("name=\"user[password]\"\r\n\r\n[REDACTED]\r\n--XyZ", $redacted);
        self::assertStringContainsString("Ada\r\n", $redacted);
        self::assertStringContainsString('file-content', $redacted);
    }

    public function testRedactBodyHandlesMultipartWithLfLineEndings(): void
    {
        $body = "--b1\nContent-Disposition: form-data; name=\"password\"\n\nhunter2\n--b1\nContent-Disposition: form-data; name=\"x\"\n\nlast--b1--";

        $redacted = $this->redactor->redactBody($body, 'multipart/form-data; boundary=b1');

        self::assertSame(
            "--b1\nContent-Disposition: form-data; name=\"password\"\n\n[REDACTED]\n--b1\nContent-Disposition: form-data; name=\"x\"\n\nlast--b1--",
            $redacted,
        );
    }

    public function testRedactBodyRedactsMultipartFieldWithoutTrailingLineEnding(): void
    {
        $body = "--b1\nContent-Disposition: form-data; name=\"password\"\n\nhunter2";

        self::assertSame(
            "--b1\nContent-Disposition: form-data; name=\"password\"\n\n[REDACTED]",
            $this->redactor->redactBody($body, 'multipart/form-data; boundary=b1'),
        );
    }

    public function testRedactBodyReturnsPlaceholderForMultipartWithoutBoundary(): void
    {
        self::assertSame(
            HttpLogRedactor::UNPARSEABLE_PLACEHOLDER,
            $this->redactor->redactBody('password=x', 'multipart/form-data'),
        );
    }
}
