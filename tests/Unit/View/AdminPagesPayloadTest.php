<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\View;

use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Tests\Support\AdminPageFixtures;
use Nowo\HttpLogBundle\View\AdminPages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Display data of the entry page payload sections ({@see AdminPages::payload()}).
 */
final class AdminPagesPayloadTest extends TestCase
{
    #[Test]
    public function headersAndQueryParametersBecomeNameValueRowsWithRedactedValuesFlagged(): void
    {
        $entry = (new HttpLogEntry())
            ->setQueryParams(['page' => '2', 'token' => '[REDACTED]', 'tags' => ['a', 'b'], 'filter' => ['status' => 404]])
            ->setRequestHeaders(['accept' => ['text/html', 'application/json'], 'cookie' => '[REDACTED]', 'x-flag' => [true], 'x-empty' => [null]])
            ->setResponseHeaders(null);

        $payload = AdminPageFixtures::pages()->payload($entry);

        self::assertSame([
            ['name' => 'page', 'value' => '2', 'redacted' => false],
            ['name' => 'token', 'value' => '[REDACTED]', 'redacted' => true],
            ['name' => 'tags', 'value' => 'a, b', 'redacted' => false],
            ['name' => 'filter', 'value' => '{"status":404}', 'redacted' => false],
        ], $payload['query_params']);
        self::assertSame([
            ['name' => 'accept', 'value' => 'text/html, application/json', 'redacted' => false],
            ['name' => 'cookie', 'value' => '[REDACTED]', 'redacted' => true],
            ['name' => 'x-flag', 'value' => 'true', 'redacted' => false],
            ['name' => 'x-empty', 'value' => '', 'redacted' => false],
        ], $payload['request_headers']);
        self::assertSame([], $payload['response_headers']);
        self::assertNull($payload['request_body']);
    }

    #[Test]
    public function unparseableRedactionPlaceholderIsFlaggedToo(): void
    {
        self::assertTrue(AdminPages::keyValueRows(['authorization' => '[REDACTED-UNPARSEABLE]'])[0]['redacted']);
        self::assertSame('{}', AdminPages::keyValueRows(['object' => new stdClass()])[0]['value']);
    }

    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function bodies(): iterable
    {
        yield 'json object' => ['{"id":1,"url":"https://x.test/a","name":"Zoë"}', "{\n    \"id\": 1,\n    \"url\": \"https://x.test/a\",\n    \"name\": \"Zoë\"\n}"];
        yield 'json list' => ['[1,2]', "[\n    1,\n    2\n]"];
        yield 'truncated json stays raw' => ['{"id":1,"na', '{"id":1,"na'];
        yield 'json scalar stays raw' => ['"text"', '"text"'];
        yield 'form data stays raw' => ['a=1&b=2', 'a=1&b=2'];
        yield 'html stays raw' => ['<p>hi</p>', '<p>hi</p>'];
        yield 'empty' => ['', ''];
        yield 'null' => [null, null];
    }

    #[Test]
    #[DataProvider('bodies')]
    public function jsonBodiesArePrettyPrinted(?string $body, ?string $expected): void
    {
        self::assertSame($expected, AdminPages::formatBody($body));

        $entry = (new HttpLogEntry())->setResponseBody($body);
        self::assertSame($expected, AdminPageFixtures::pages()->payload($entry)['response_body']);
    }
}
