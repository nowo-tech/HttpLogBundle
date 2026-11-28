<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\HttpClient;

use Generator;
use Nowo\HttpLogBundle\HttpClient\OutboundErrorCodeExtractor;
use Nowo\HttpLogBundle\Tests\Support\UsageTestRig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Throwable;

use function array_column;
use function array_map;
use function count;
use function intval;
use function json_encode;
use function str_repeat;

/**
 * The provider error code of a failed outbound call is stored (allowlisted); the response body never is, and the
 * call itself behaves as with the bare client.
 */
final class OutboundErrorCodeCaptureTest extends TestCase
{
    private const URL = 'https://login.example.com/tenant-42/oauth2/v2.0/token';

    private const PROVIDERS = [
        'login' => ['hosts' => ['login.example.com'], 'error_code' => ['paths' => ['error', 'error_codes'], 'prefix' => 'ERR']],
        'plain' => ['hosts' => ['plain.example.com']],
    ];

    #[Test]
    public function aFailedCallStoresTheCodeAndNeverTheBody(): void
    {
        $body = (string) json_encode(['error' => 'invalid_client', 'error_description' => 'ERR7000215: wrong secret for jane@example.com', 'error_codes' => [7000215]]);
        $rig  = new UsageTestRig(true);
        $http = $rig->client(new MockHttpClient(new MockResponse($body, ['http_code' => 401])), self::PROVIDERS);

        self::assertSame($body, $http->request('POST', self::URL)->getContent(false));

        $rig->persist();
        $rows = $rig->rows();
        self::assertSame(['invalid_client,ERR7000215'], array_column($rows, 'error_code'));
        self::assertSame([401], array_map(intval(...), array_column($rows, 'status_code')));
        self::assertStringNotContainsString('jane@example.com', (string) json_encode($rows));
        self::assertStringNotContainsString('wrong secret', (string) json_encode($rows));
    }

    #[Test]
    public function withoutTheColumnTheCodeIsDroppedAndTheRowStillWritten(): void
    {
        $rig  = new UsageTestRig();
        $http = $rig->client(new MockHttpClient(new MockResponse('{"error":"invalid_client"}', ['http_code' => 401])), self::PROVIDERS);
        self::assertSame('{"error":"invalid_client"}', $http->request('POST', self::URL)->getContent(false));

        $rig->persist();
        $rows = $rig->rows();
        self::assertCount(1, $rows);
        self::assertArrayNotHasKey('error_code', $rows[0]);
        self::assertSame([], $rig->logs);
    }

    #[Test]
    public function aStreamedBodyInSeveralChunksIsRead(): void
    {
        $rig  = new UsageTestRig(true);
        $http = $rig->client(new MockHttpClient(new MockResponse(['{"error":', '"invalid_', 'grant"}'], ['http_code' => 400])), self::PROVIDERS);
        self::assertSame('{"error":"invalid_grant"}', $http->request('POST', self::URL)->getContent(false));

        $rig->persist();
        self::assertSame(['invalid_grant'], array_column($rig->rows(), 'error_code'));
    }

    #[Test]
    public function successesProvidersWithoutAnExtractorAndNonJsonBodiesHaveNoCode(): void
    {
        $rig = new UsageTestRig(true);
        $ok  = $rig->client(new MockHttpClient(new MockResponse('{"error":"not_an_error"}', ['http_code' => 200])), self::PROVIDERS);
        self::assertSame('{"error":"not_an_error"}', $ok->request('GET', self::URL)->getContent());

        $plain = $rig->client(new MockHttpClient(new MockResponse('{"error":"invalid_client"}', ['http_code' => 401])), self::PROVIDERS);
        $plain->request('GET', 'https://plain.example.com/x')->getContent(false);

        $html = $rig->client(new MockHttpClient(new MockResponse('<html>Unauthorized</html>', ['http_code' => 401])), self::PROVIDERS);
        $html->request('GET', self::URL)->getContent(false);

        $empty = $rig->client(new MockHttpClient(new MockResponse('', ['http_code' => 502])), self::PROVIDERS);
        $empty->request('GET', self::URL)->getContent(false);

        $rig->persist();
        self::assertSame([null, null, null, null], array_column($rig->rows(), 'error_code'));
        self::assertSame(4, count($rig->rows()));
    }

    #[Test]
    public function aBodyOverTheCapGivesNoCodeAndIsStillForwardedWhole(): void
    {
        $body = '{"error":"invalid_client","pad":"' . str_repeat('a', OutboundErrorCodeExtractor::MAX_BODY_BYTES) . '"}';
        $rig  = new UsageTestRig(true);
        $http = $rig->client(new MockHttpClient(new MockResponse((static function () use ($body): Generator {
            yield $body;
            yield 'tail';
        })(), ['http_code' => 401])), self::PROVIDERS);

        self::assertSame($body . 'tail', $http->request('POST', self::URL)->getContent(false));

        $rig->persist();
        self::assertSame([null], array_column($rig->rows(), 'error_code'));
    }

    #[Test]
    public function aTransportErrorHasNoCode(): void
    {
        $rig  = new UsageTestRig(true);
        $http = $rig->client(new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host: login.example.com'])), self::PROVIDERS);
        try {
            $http->request('POST', self::URL)->getStatusCode();
        } catch (Throwable) {
        }

        $rig->persist();
        self::assertSame([null], array_column($rig->rows(), 'error_code'));
        self::assertSame(['dns'], array_column($rig->rows(), 'error_kind'));
    }

    #[Test]
    public function aScopedClientWithARelativeUrlResolvesItsProviderFromTheOriginalUrl(): void
    {
        $rig  = new UsageTestRig(true);
        $http = $rig->client(new MockHttpClient(new MockResponse('{"error":"invalid_client"}', ['http_code' => 401])), self::PROVIDERS, clientId: 'login.client');
        $http->request('POST', '/tenant-42/oauth2/v2.0/token', ['base_uri' => 'https://login.example.com'])->getContent(false);

        $rig->persist();
        self::assertSame(['invalid_client'], array_column($rig->rows(), 'error_code'));
    }
}
