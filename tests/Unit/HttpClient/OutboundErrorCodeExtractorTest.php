<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\HttpClient;

use Nowo\HttpLogBundle\HttpClient\OutboundErrorCodeExtractor;
use Nowo\HttpLogBundle\HttpClient\OutboundErrorCodeSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function json_encode;
use function str_repeat;
use function strlen;

/**
 * The provider error code is read from a few configured paths and allowlisted: never free text.
 */
final class OutboundErrorCodeExtractorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: OutboundErrorCodeSpec, 2: string|null}>
     */
    public static function bodies(): iterable
    {
        yield 'login: string and integer codes with a prefix' => [
            '{"error":"invalid_client","error_description":"AADSTS7000215: Invalid client secret for jane@example.com","error_codes":[7000215],"trace_id":"0f6c2a1e-1111-2222-3333-444455556666"}',
            new OutboundErrorCodeSpec(['error', 'error_codes'], 'AADSTS'),
            'invalid_client,AADSTS7000215',
        ];
        yield 'integer array keeps five values' => [
            '{"error_codes":[1,2,3,4,5,6,7]}',
            new OutboundErrorCodeSpec(['error_codes'], 'E'),
            'E1,E2,E3,E4,E5',
        ];
        yield 'integers without a prefix' => ['{"error_codes":[50126]}', new OutboundErrorCodeSpec(['error_codes']), '50126'];
        yield 'graph nested dotted path' => ['{"error":{"code":"InvalidAuthenticationToken","message":"Access token is empty."}}', new OutboundErrorCodeSpec(['error.code']), 'InvalidAuthenticationToken'];
        yield 'json pointer' => ['{"error":{"code":"card_declined"}}', new OutboundErrorCodeSpec(['/error/code']), 'card_declined'];
        yield 'stripe two paths' => ['{"error":{"code":"card_declined","decline_code":"insufficient_funds"}}', new OutboundErrorCodeSpec(['error.code', 'error.decline_code']), 'card_declined,insufficient_funds'];
        yield 'google plain string' => ['{"error":"invalid_grant"}', new OutboundErrorCodeSpec(['error']), 'invalid_grant'];
        yield 'clickup ECODE' => ['{"err":"Token invalid","ECODE":"OAUTH_025"}', new OutboundErrorCodeSpec(['ECODE']), 'OAUTH_025'];
        yield 'slack' => ['{"ok":false,"error":"invalid_auth"}', new OutboundErrorCodeSpec(['error']), 'invalid_auth'];
        yield 'resend name' => ['{"statusCode":401,"name":"missing_api_key","message":"Missing API key."}', new OutboundErrorCodeSpec(['name']), 'missing_api_key'];
        yield 'missing path is skipped' => ['{"error":"x_y"}', new OutboundErrorCodeSpec(['nope', 'error']), 'x_y'];
        yield 'no path present' => ['{"a":1}', new OutboundErrorCodeSpec(['error']), null];
        yield 'path through a scalar' => ['{"error":"x"}', new OutboundErrorCodeSpec(['error.code']), null];
        yield 'empty list' => ['{"error_codes":[]}', new OutboundErrorCodeSpec(['error_codes']), null];

        yield 'an e-mail is unknown' => ['{"error":"jane@example.com"}', new OutboundErrorCodeSpec(['error']), 'unknown'];
        yield 'a sentence is unknown' => ['{"error":"The client secret is wrong for tenant Acme"}', new OutboundErrorCodeSpec(['error']), 'unknown'];
        yield 'a JWT-shaped string is unknown' => ['{"error":"eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln"}', new OutboundErrorCodeSpec(['error']), 'unknown'];
        yield 'a three-segment token is unknown' => ['{"error":"abcdefgh.ijklmnop.qrstuvwx"}', new OutboundErrorCodeSpec(['error']), 'unknown'];
        yield 'a UUID is unknown' => ['{"error":"0f6c2a1e-1111-2222-3333-444455556666"}', new OutboundErrorCodeSpec(['error']), 'unknown'];
        yield 'a long hex id is unknown' => ['{"error":"0123456789abcdef01234567"}', new OutboundErrorCodeSpec(['error']), 'unknown'];
        yield 'a long description is unknown' => ['{"error":"' . str_repeat('a', 65) . '"}', new OutboundErrorCodeSpec(['error']), 'unknown'];
        yield 'an object is unknown' => ['{"error":{"code":"x"}}', new OutboundErrorCodeSpec(['error']), 'unknown'];
        yield 'a boolean and a float are unknown' => ['{"a":true,"b":1.5}', new OutboundErrorCodeSpec(['a', 'b']), 'unknown,unknown'];
        yield 'an array of objects' => ['{"errors":[{"code":"x"}]}', new OutboundErrorCodeSpec(['errors']), 'unknown'];
        yield 'an empty string is unknown' => ['{"error":""}', new OutboundErrorCodeSpec(['error']), 'unknown'];

        yield 'non-JSON body' => ['<html>Unauthorized</html>', new OutboundErrorCodeSpec(['error']), null];
        yield 'a JSON scalar' => ['"invalid_client"', new OutboundErrorCodeSpec(['error']), null];
        yield 'an empty body' => ['', new OutboundErrorCodeSpec(['error']), null];
        yield 'too deep' => [str_repeat('[', 40) . str_repeat(']', 40), new OutboundErrorCodeSpec(['error']), null];
        yield 'over the size cap' => ['{"error":"x_y","pad":"' . str_repeat('a', OutboundErrorCodeExtractor::MAX_BODY_BYTES) . '"}', new OutboundErrorCodeSpec(['error']), null];
    }

    #[Test]
    #[DataProvider('bodies')]
    public function extractsOnlyAllowlistedCodes(string $body, OutboundErrorCodeSpec $spec, ?string $expected): void
    {
        self::assertSame($expected, OutboundErrorCodeExtractor::extract($body, $spec));
    }

    #[Test]
    public function theJoinedValueNeverPassesEightPartsOrOneHundredTwentyEightBytes(): void
    {
        $many = (string) json_encode(['a' => ['c1', 'c2', 'c3', 'c4', 'c5'], 'b' => ['d1', 'd2', 'd3', 'd4', 'd5']]);
        self::assertSame('c1,c2,c3,c4,c5,d1,d2,d3', OutboundErrorCodeExtractor::extract($many, new OutboundErrorCodeSpec(['a', 'b'])));

        $long = (string) json_encode(['a' => str_repeat('x', 60), 'b' => str_repeat('y', 60), 'c' => str_repeat('z', 60)]);
        $code = OutboundErrorCodeExtractor::extract($long, new OutboundErrorCodeSpec(['a', 'b', 'c']));
        self::assertSame(str_repeat('x', 60) . ',' . str_repeat('y', 60), $code);
        self::assertLessThanOrEqual(128, strlen($code));
    }
}
