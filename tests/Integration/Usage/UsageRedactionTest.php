<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Usage;

use Closure;
use Generator;
use Nowo\HttpLogBundle\Tests\Support\UsageTestRig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function base64_encode;
use function json_encode;
use function rawurlencode;
use function stripos;
use function strtolower;
use function urlencode;

/**
 * HL-1 §4.3, the redaction test list (outbound cases 1–10, and 16 for each of them): every column of every persisted
 * row, cast to string, and every serialized `PersistHttpLogMessage`, must not contain a seeded secret, plain
 * (case-insensitive), URL-encoded or base64. Plus the schema rule: the six body / header / query columns are NULL.
 */
final class UsageRedactionTest extends TestCase
{
    private const S3_URL = 'https://bucket.s3.amazonaws.com/uploads/report.pdf?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=TESTKEYIDSEED3%2F20261108%2Feu-west-1%2Fs3%2Faws4_request&X-Amz-Signature=SEED4';

    /** Columns that are always NULL on usage rows. */
    private const ALWAYS_NULL = ['query_params', 'request_headers', 'response_headers', 'request_body', 'response_body', 'body_content_type'];

    /**
     * @return iterable<string, array{0: list<string>, 1: Closure(UsageTestRig): void}>
     */
    public static function cases(): iterable
    {
        yield '1 credential headers' => [['test-token-placeholder-SEED1', 'SEED2'], static function (UsageTestRig $rig): void {
            $rig->client(new MockHttpClient(new MockResponse('ok', ['response_headers' => ['Set-Cookie: session=SEED2']])))
                ->request('GET', 'https://api.example.com/v1/me', ['headers' => ['Authorization' => 'Bearer test-token-placeholder-SEED1', 'X-Api-Key' => 'SEED2']])
                ->getContent();
        }];
        yield '2 S3 presigned GET' => [['TESTKEYIDSEED3', 'SEED4', 'X-Amz'], static function (UsageTestRig $rig): void {
            $rig->client(new MockHttpClient(new MockResponse('pdf')), ['s3' => ['hosts' => ['*.amazonaws.com'], 'path_depth' => 1]])->request('GET', self::S3_URL)->getContent();
        }];
        yield '3 query string and fragment' => [['SEED5', 'SEED6', 'SEED7', 'SEED8'], static function (UsageTestRig $rig): void {
            $rig->client(new MockHttpClient(new MockResponse('ok')))->request('GET', 'https://api.example.com/v1/x?token=SEED5&sig=SEED6&api_key=SEED7#access_token=SEED8')->getContent();
        }];
        yield '4 user info' => [['SEED9', 'user:'], static function (UsageTestRig $rig): void {
            $rig->client(new MockHttpClient(new MockResponse('ok')))->request('GET', 'https://user:SEED9@host.example/x')->getContent();
        }];
        yield '5 Slack webhook' => [['SEED10', 'B0DEF', 'T0ABC'], static function (UsageTestRig $rig): void {
            $rig->client(new MockHttpClient(new MockResponse('ok')))->request('POST', 'https://hooks.slack.com/services/T0ABC/B0DEF/SEED10-placeholder', ['json' => ['text' => 'hi']])->getContent();
        }];
        yield '6 Telegram bot token' => [['SEED11', '123456'], static function (UsageTestRig $rig): void {
            $rig->client(new MockHttpClient(new MockResponse('ok')))->request('POST', 'https://api.telegram.org/bot123456:SEED11-abc/sendMessage')->getContent();
        }];
        yield '7 Discord webhook' => [['SEED12', '1234567890'], static function (UsageTestRig $rig): void {
            $rig->client(new MockHttpClient(new MockResponse('ok')))->request('POST', 'https://discord.com/api/webhooks/1234567890/SEED12-placeholder')->getContent();
        }];
        yield '8 e-mail and UUID in the path' => [['ana@example.com', '0f8fad5b-d9cb-469f-a165-70867728950e'], static function (UsageTestRig $rig): void {
            $rig->client(new MockHttpClient(new MockResponse('ok')), ['files' => ['hosts' => ['api.example.com'], 'path_depth' => 4]])
                ->request('GET', 'https://api.example.com/users/ana@example.com/files/0f8fad5b-d9cb-469f-a165-70867728950e')->getContent();
        }];
        yield '9 transport errors carrying URL 2' => [['TESTKEYIDSEED3', 'SEED4', 'Idle timeout', 'resolve host'], static function (UsageTestRig $rig): void {
            $timeout = $rig->client(new MockHttpClient(new MockResponse((static function (): Generator {
                yield '';
            })())));
            $dns = $rig->client(new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host: bucket.s3.amazonaws.com for "' . self::S3_URL . '"'])));
            foreach ([$timeout, $dns] as $client) {
                try {
                    $client->request('GET', self::S3_URL, ['timeout' => 0.05])->getContent();
                } catch (Throwable) {
                }
            }
        }];
        yield '10 JSON request and response bodies' => [['SEED13'], static function (UsageTestRig $rig): void {
            $rig->client(new MockHttpClient(new MockResponse('{"echo":"SEED13"}', ['response_headers' => ['content-type: application/json']])))
                ->request('POST', 'https://api.example.com/v1/items', ['json' => ['secret' => 'SEED13']])->toArray();
        }];
    }

    /**
     * @param list<string> $seeds
     * @param Closure(UsageTestRig): void $scenario
     */
    #[Test]
    #[DataProvider('cases')]
    public function noSeedReachesARowOrAMessage(array $seeds, Closure $scenario): void
    {
        $rig = new UsageTestRig();
        $scenario($rig);
        $bodies = $rig->persist();
        $rows   = $rig->rows();

        self::assertNotSame([], $rows, 'the scenario produced rows');
        foreach ($rows as $row) {
            foreach (self::ALWAYS_NULL as $column) {
                self::assertNull($row[$column], $column);
            }
            foreach ($row as $column => $value) {
                $this->assertNoSeed($seeds, (string) $value, 'column ' . $column);
            }
        }
        foreach ($bodies as $body) {
            $this->assertNoSeed($seeds, $body, 'serialized PersistHttpLogMessage (failure transport, case 16)');
        }
        $this->assertNoSeed($seeds, (string) json_encode($rig->logs), 'logs');
    }

    /** @param list<string> $seeds */
    private function assertNoSeed(array $seeds, string $haystack, string $where): void
    {
        foreach ($seeds as $seed) {
            foreach ([$seed, urlencode($seed), rawurlencode($seed), base64_encode($seed)] as $form) {
                self::assertFalse(stripos($haystack, $form) !== false, $where . ' contains "' . $form . '": ' . strtolower($haystack));
            }
        }
    }
}
