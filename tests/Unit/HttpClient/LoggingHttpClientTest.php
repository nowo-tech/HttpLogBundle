<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\HttpClient;

use Closure;
use DateTimeImmutable;
use Generator;
use Nowo\HttpLogBundle\Context\HttpLogFrame;
use Nowo\HttpLogBundle\HttpClient\HttpLogHttpClientWrapper;
use Nowo\HttpLogBundle\HttpClient\LoggingHttpClient;
use Nowo\HttpLogBundle\HttpClient\OutboundCall;
use Nowo\HttpLogBundle\HttpClient\OutboundCapturePolicy;
use Nowo\HttpLogBundle\HttpClient\OutboundTarget;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Tests\Support\UsageTestRig;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;

use function array_column;
use function count;
use function is_string;
use function iterator_to_array;

/**
 * HL-1 §5.1 / §7.1: the decorator is invisible to the caller (same status, headers, body, chunks and exceptions as
 * the bare client, still lazy) and records exactly one row per logical call.
 */
final class LoggingHttpClientTest extends TestCase
{
    private const URL = 'https://api.example.com/v2/items/42?token=test-token-placeholder';

    /**
     * @return iterable<string, array{0: Closure(): MockResponse, 1: Closure(HttpClientInterface, ResponseInterface): array<string, mixed>, 2: array<string, mixed>|null}>
     */
    public static function scenarios(): iterable
    {
        $read = static fn (HttpClientInterface $c, ResponseInterface $r): array => [
            'status'  => $r->getStatusCode(),
            'headers' => $r->getHeaders(false),
            'content' => $r->getContent(false),
        ];

        yield 'json 200' => [
            static fn (): MockResponse => new MockResponse('{"a":1}', ['response_headers' => ['content-type: application/json; charset=utf-8']]),
            static fn (HttpClientInterface $c, ResponseInterface $r): array => $read($c, $r) + ['array' => $r->toArray()],
            ['status_code' => 200, 'error_kind' => null, 'content_type' => 'application/json', 'retry_count' => 0],
        ];
        yield '404 throws the same exception' => [
            static fn (): MockResponse => new MockResponse('nope', ['http_code' => 404]),
            static fn (HttpClientInterface $c, ResponseInterface $r): array => $r->getContent(),
            ['status_code' => 404, 'error_kind' => 'http_4xx'],
        ];
        yield '503' => [
            static fn (): MockResponse => new MockResponse('down', ['http_code' => 503]),
            $read,
            ['status_code' => 503, 'error_kind' => 'http_5xx'],
        ];
        yield 'stream chunks in order' => [
            static fn (): MockResponse => new MockResponse(['a', 'b', 'c']),
            static function (HttpClientInterface $c, ResponseInterface $r): array {
                $chunks = [];
                foreach ($c->stream($r) as $chunk) {
                    $chunks[] = [$chunk->isFirst(), $chunk->isLast(), $chunk->getContent()];
                }

                return $chunks;
            },
            ['status_code' => 200, 'error_kind' => null],
        ];
        yield 'cancel' => [
            static fn (): MockResponse => new MockResponse((static function (): Generator {
                yield 'a';
                yield 'b';
            })()),
            static function (HttpClientInterface $c, ResponseInterface $r): array {
                $r->cancel();

                return ['canceled' => $r->getInfo('canceled'), 'error' => $r->getInfo('error')];
            },
            ['status_code' => null, 'error_kind' => 'cancelled'],
        ];
        yield 'destruct without read' => [
            static fn (): MockResponse => new MockResponse('ok'),
            static fn (HttpClientInterface $c, ResponseInterface $r): array => [],
            ['status_code' => 200, 'error_kind' => null],
        ];
        yield 'idle timeout' => [
            static fn (): MockResponse => new MockResponse((static function (): Generator {
                yield '';
            })()),
            static fn (HttpClientInterface $c, ResponseInterface $r): array => [$r->getContent()],
            ['status_code' => null, 'error_kind' => 'timeout'],
        ];
        yield 'recoverable stream timeout then success' => [
            static fn (): MockResponse => new MockResponse((static function (): Generator {
                yield 'a';
                yield '';
                yield 'b';
            })()),
            static function (HttpClientInterface $c, ResponseInterface $r): array {
                $seen = [];
                foreach ($c->stream($r, 0.0) as $chunk) {
                    $seen[] = $chunk->isTimeout() ? 'timeout' : $chunk->getContent();
                }

                return [$seen, $r->getContent()];
            },
            ['status_code' => 200, 'error_kind' => null],
        ];
        yield 'dns failure' => [
            static fn (): MockResponse => new MockResponse('', ['error' => 'Could not resolve host: api.example.com']),
            static fn (HttpClientInterface $c, ResponseInterface $r): array => [$r->getStatusCode()],
            ['status_code' => null, 'error_kind' => 'dns'],
        ];
    }

    /**
     * @param Closure(): MockResponse $response
     * @param Closure(HttpClientInterface, ResponseInterface): array<string, mixed> $act
     * @param array<string, mixed>|null $row expected columns of the single row
     */
    #[Test]
    #[DataProvider('scenarios')]
    public function behavesLikeTheBareClientAndRecordsOnce(Closure $response, Closure $act, ?array $row): void
    {
        $bare   = $this->observe(new MockHttpClient($response()), $act);
        $rig    = new UsageTestRig();
        $logged = $this->observe($rig->client(new MockHttpClient($response())), $act);

        self::assertEquals($bare, $logged);

        $rig->persist();
        $rows = $rig->rows();
        self::assertCount(1, $rows);
        foreach ((array) $row as $column => $value) {
            self::assertSame($value, $rows[0][$column] === null ? null : (is_numeric($rows[0][$column]) && !is_string($value) ? (int) $rows[0][$column] : $rows[0][$column]), $column);
        }
        self::assertSame('outbound', $rows[0]['channel']);
        self::assertSame('/v2/items/{…}', $rows[0]['path']);
        self::assertSame('api.example.com', $rows[0]['host']);
        self::assertNull($rows[0]['query_params']);
    }

    #[Test]
    public function requestIsLazyAndRecordsNothingBeforeTheResponseCompletes(): void
    {
        $started = false;
        $rig     = new UsageTestRig();
        $client  = $rig->client(new MockHttpClient(static function () use (&$started): MockResponse {
            return new MockResponse((static function () use (&$started): Generator {
                $started = true;
                yield 'x';
            })());
        }));

        $response = $client->request('GET', self::URL);
        self::assertInstanceOf(AsyncResponse::class, $response);
        self::assertFalse($started, 'request() returned before the first byte');
        self::assertSame(0, $rig->buffer->count());

        $response->getContent();
        self::assertSame(1, $rig->buffer->count());
        $response->getContent();
        unset($response);
        self::assertSame(1, $rig->buffer->count(), 'exactly once');
    }

    #[Test]
    public function oneRowPerLogicalCallWithItsRetryCount(): void
    {
        $rig    = new UsageTestRig();
        $retry  = new RetryableHttpClient(new MockHttpClient([new MockResponse('', ['http_code' => 500]), new MockResponse('ok')]), new GenericRetryStrategy([500], 0), 2);
        $client = $rig->client($retry);

        self::assertSame('ok', $client->request('GET', self::URL)->getContent());
        $rig->persist();

        $rows = $rig->rows();
        self::assertCount(1, $rows);
        self::assertSame(1, (int) $rows[0]['retry_count']);
        self::assertSame(200, (int) $rows[0]['status_code']);
    }

    #[Test]
    public function aStoppedRedirectChainIsARedirectLimitError(): void
    {
        $rig    = new UsageTestRig();
        $inner  = new RedirectCountClient(new MockHttpClient(new MockResponse('', ['http_code' => 302, 'response_headers' => ['Location: https://api.example.com/next']])), 3);
        $client = $rig->client($inner);

        $response = $client->request('GET', self::URL, ['max_redirects' => 3]);
        self::assertSame(302, $response->getStatusCode());
        $response->getContent(false);
        $rig->persist();

        self::assertSame(['redirect_limit'], array_column($rig->rows(), 'error_kind'));
        self::assertNull($rig->rows()[0]['status_code']);
    }

    #[Test]
    public function responseSizeComesFromTheTransportInfo(): void
    {
        $rig = new UsageTestRig();
        foreach ([7.0, 9, '11', 'n/a'] as $size) {
            $rig->client(new RedirectCountClient(new MockHttpClient(new MockResponse('ok')), 0, ['size_download' => $size, 'content_type' => 'text/plain']))->request('GET', self::URL)->getContent();
        }
        $rig->persist();

        self::assertSame(['7', '9', '11', ''], array_map(static fn (mixed $v): string => (string) $v, array_column($rig->rows(), 'response_bytes')));
        self::assertSame('text/plain', $rig->rows()[0]['content_type']);
    }

    #[Test]
    public function fewerRedirectsOrA304AreNotErrors(): void
    {
        $rig = new UsageTestRig();
        foreach ([[302, 1], [304, 20]] as [$status, $count]) {
            $inner = new RedirectCountClient(new MockHttpClient(new MockResponse('', ['http_code' => $status, 'response_headers' => ['HTTP/1.1 100 Continue', 'Location: /old', 'HTTP/1.1 ' . $status . ' X', 'Location: https://api.example.com/next']])), $count);
            $rig->client($inner)->request('GET', self::URL)->getContent(false);
        }
        $rig->client(new RedirectCountClient(new MockHttpClient(new MockResponse('', ['http_code' => 302, 'response_headers' => [7]])), 20))->request('GET', self::URL)->getContent(false);
        $rig->persist();

        self::assertSame([null, null, null], array_column($rig->rows(), 'error_kind'));
    }

    #[Test]
    public function ownOptionsAreRemovedAndApplied(): void
    {
        $rig    = new UsageTestRig();
        $seen   = [];
        $client = $rig->client(new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options['extra'] ?? 'no extra';

            return new MockResponse('ok');
        }), ['clickup' => ['hosts' => ['other.example']]]);

        $client->request('GET', self::URL, ['extra' => ['nowo_http_log' => ['tenant_key' => 'org-9', 'project_key' => 'project-9', 'provider' => 'clickup']]])->getContent();
        $client->request('GET', self::URL, ['extra' => ['trace' => 'x', 'nowo_http_log' => ['skip' => true]]])->getContent();
        $client->request('GET', self::URL, ['extra' => ['nowo_http_log' => ['tenant_key' => 'Bearer test-token-placeholder']]])->getContent();
        $rig->persist();

        self::assertSame(['no extra', ['trace' => 'x'], 'no extra'], array_map(static fn (mixed $e): mixed => $e === [] ? 'no extra' : $e, $seen));
        $rows = $rig->rows();
        self::assertCount(2, $rows, 'skip: no row');
        self::assertSame(['org-9', null], array_column($rows, 'tenant_key'));
        self::assertSame(['project-9', null], array_column($rows, 'project_key'));
        self::assertSame(['clickup', 'other'], array_column($rows, 'provider'));
    }

    #[Test]
    public function aRelativeUrlIsResolvedFromTheResponse(): void
    {
        $rig    = new UsageTestRig();
        $client = $rig->client(new MockHttpClient([new MockResponse('ok'), new MockResponse('ok')], 'https://api.clickup.com'), ['clickup' => ['hosts' => ['api.clickup.com'], 'path_templates' => ['/api/v2/task/{id}']]], recordUnmapped: false);
        $client->request('GET', '/api/v2/task/86abc?token=test-token-placeholder')->getContent();

        $other = $rig->client(new MockHttpClient(new MockResponse('ok'), 'https://unknown.example'), recordUnmapped: false);
        $other->request('GET', '/x')->getContent();
        $rig->persist();

        self::assertSame(['/api/v2/task/{id}'], array_column($rig->rows(), 'path'));
        self::assertSame('api.clickup.com', $rig->rows()[0]['host']);
    }

    #[Test]
    public function contextIsSnapshotAtRequestTime(): void
    {
        $rig      = new UsageTestRig();
        $client   = $rig->client(new MockHttpClient(new MockResponse('ok')));
        $response = $rig->context->runFrame(new HttpLogFrame('org-1', 'project-1', 'msg-1'), static fn (): ResponseInterface => $client->request('GET', self::URL));

        $rig->context->run('org-2', static fn (): string => $response->getContent());
        $rig->persist();

        self::assertSame('org-1', $rig->rows()[0]['tenant_key']);
        self::assertSame('project-1', $rig->rows()[0]['project_key']);
        self::assertSame('msg-1', $rig->rows()[0]['messenger_message_id']);
    }

    #[Test]
    public function samplingKeepsErrorsAndPerCallFalseKeepsNothing(): void
    {
        $rig    = new UsageTestRig();
        $client = $rig->client(new MockHttpClient([new MockResponse('ok'), new MockResponse('no', ['http_code' => 429])]), samplingRate: 0.0);
        $client->request('GET', self::URL)->getContent(false);
        $client->request('GET', self::URL)->getContent(false);

        $uptime = $rig->client(new MockHttpClient(new MockResponse('', ['http_code' => 500])), ['uptime' => ['clients' => ['uptime.http_client'], 'per_call' => false]], clientId: 'uptime.http_client');
        $uptime->request('GET', 'https://probe.example/')->getContent(false);

        $unmapped = $rig->client(new MockHttpClient(new MockResponse('ok')), recordUnmapped: false);
        $unmapped->request('GET', 'https://unknown.example/')->getContent();
        $rig->persist();

        self::assertSame(['429'], array_map(strval(...), array_column($rig->rows(), 'status_code')));
    }

    #[Test]
    public function aSynchronousRequestErrorIsRecordedAndRethrownUnchanged(): void
    {
        $rig    = new UsageTestRig();
        $error  = new TransportException('Unsupported option for "https://api.example.com/?token=test-token-placeholder"');
        $client = $rig->client(new MockHttpClient(static function () use ($error): never {
            throw $error;
        }));

        try {
            $client->request('GET', self::URL);
            self::fail('expected the inner exception');
        } catch (TransportException $caught) {
            self::assertSame($error, $caught);
        }
        $rig->persist();

        self::assertSame(['transport'], array_column($rig->rows(), 'error_kind'));
    }

    #[Test]
    public function captureIsSuspendedWhileTheBundleWrites(): void
    {
        $rig    = new UsageTestRig();
        $client = $rig->client(new MockHttpClient(static fn (): MockResponse => new MockResponse('ok')));

        // e.g. a Messenger transport (SQS) using the decorated client while the buffer dispatches
        $rig->buffer->suspended(static fn (): string => $client->request('GET', self::URL)->getContent());
        self::assertSame(0, $rig->buffer->count());

        $client->request('GET', self::URL)->getContent();
        $rig->persist();
        self::assertCount(1, $rig->rows());
    }

    #[Test]
    public function aFailingPolicyOrBufferNeverBreaksTheCall(): void
    {
        $rig    = new UsageTestRig();
        $policy = $this->createMock(OutboundCapturePolicy::class);
        $policy->method('target')->willThrowException(new RuntimeException('policy bug'));
        $client = new LoggingHttpClient(new MockHttpClient(new MockResponse('ok')), $policy, $rig->buffer, $rig->context, $rig->clock, logger: $rig->logger);
        self::assertSame('ok', $client->request('GET', self::URL)->getContent());

        $buffer = $this->createMock(HttpLogBuffer::class);
        $buffer->method('add')->willThrowException(new RuntimeException('buffer bug with ' . self::URL));
        $client = new LoggingHttpClient(new MockHttpClient(new MockResponse('ok')), new OutboundCapturePolicy(), $buffer, $rig->context, $rig->clock, logger: $rig->logger);
        self::assertSame('ok', $client->request('GET', self::URL)->getContent());

        self::assertSame([['exception_class' => RuntimeException::class], ['exception_class' => RuntimeException::class]], array_column($rig->logs, 'context'));

        // without a logger as well
        $quiet = new LoggingHttpClient(new MockHttpClient(new MockResponse('ok')), $policy, $rig->buffer, $rig->context, $rig->clock);
        self::assertSame('ok', $quiet->request('GET', self::URL)->getContent());
    }

    #[Test]
    public function aChunkThatBreaksTheObserverIsForwardedAndLoggedOnce(): void
    {
        $rig      = new UsageTestRig();
        $call     = new OutboundCall(new OutboundTarget('other', 'https', 'h', '/'), ['url' => 'https://h/', 'clientId' => null, 'clientProvider' => null, 'requestProvider' => null], 'GET', new DateTimeImmutable(), new HttpLogFrame(), null, null, 20, new OutboundCapturePolicy(), $rig->buffer, $rig->logger);
        $passthru = null;
        $client   = new MockHttpClient();
        $response = new MockResponse();
        $info     = [];
        $max      = 0.0;
        $context  = new AsyncContext($passthru, $client, $response, $info, null, 0, $max);

        $broken = new BrokenChunk(null);
        self::assertSame([$broken], iterator_to_array(($call->passthru())($broken, $context)));
        self::assertTrue($call->isDone());
        self::assertSame(['exception_class' => RuntimeException::class], $rig->logs[0]['context']);

        // a non-ErrorChunk reporting an error is a transport error of unknown cause
        $other = new OutboundCall(new OutboundTarget('other', 'https', 'h', '/'), ['url' => 'https://h/', 'clientId' => null, 'clientProvider' => null, 'requestProvider' => null], 'GET', new DateTimeImmutable(), new HttpLogFrame(), null, null, 20, new OutboundCapturePolicy(), $rig->buffer, $rig->logger);
        iterator_to_array(($other->passthru())(new BrokenChunk('odd failure'), $context));
        iterator_to_array(($other->passthru())(new BrokenChunk('again'), $context));
        $rig->persist();
        self::assertSame(['transport'], array_column($rig->rows(), 'error_kind'));
    }

    #[Test]
    public function withOptionsKeepsTheClientIdAndResetReachesTheInnerClient(): void
    {
        $rig    = new UsageTestRig();
        $client = $rig->client(new MockHttpClient(new MockResponse('ok')), clientId: 'clickup.client');
        $clone  = $client->withOptions(['headers' => ['X-Test' => '1']]);

        self::assertInstanceOf(LoggingHttpClient::class, $clone);
        self::assertNotSame($client, $clone);
        self::assertSame('clickup.client', $clone->getClientId());
        $client->reset();
    }

    #[Test]
    public function theWrapperIsIdempotentAndInertWhenDisabled(): void
    {
        $rig   = new UsageTestRig();
        $inner = new MockHttpClient(new MockResponse('ok'));

        self::assertSame($inner, (new HttpLogHttpClientWrapper())->wrap($inner, 's3'));

        $wrapper = new HttpLogHttpClientWrapper($rig->factory());
        $wrapped = $wrapper->wrap($inner, 's3', 's3.client');
        self::assertInstanceOf(LoggingHttpClient::class, $wrapped);
        self::assertSame($wrapped, $wrapper->wrap($wrapped, 's3'));
        self::assertSame($wrapped::class, $wrapper->wrap($wrapped->withOptions([]), 's3')::class);

        $wrapped->request('GET', 'https://bucket.s3.amazonaws.com/key')->getContent();
        $wrapper->wrap(new MockHttpClient(new MockResponse('ok')), 'Bad Provider!')->request('GET', 'https://x.example/')->getContent();
        $rig->persist();
        self::assertSame(['s3', 'other'], array_column($rig->rows(), 'provider'));
    }

    #[Test]
    public function payloadIsTheAllowlist(): void
    {
        $rig = new UsageTestRig();
        $rig->client(new MockHttpClient(new MockResponse('ok')))->request('GET', self::URL)->getContent();
        $rig->buffer->flush();

        self::assertCount(1, $rig->messages);
        self::assertInstanceOf(PersistHttpLogMessage::class, $rig->messages[0]);
        self::assertSame('outbound', $rig->messages[0]->payload['channel']);
    }

    /**
     * @param Closure(HttpClientInterface, ResponseInterface): array<string, mixed> $act
     *
     * @return array<string, mixed>
     */
    private function observe(HttpClientInterface $client, Closure $act): array
    {
        $result = ['exception' => null];

        try {
            $response         = $client->request('GET', self::URL, ['timeout' => 0.05]);
            $result['result'] = $act($client, $response);
            unset($response);
        } catch (Throwable $exception) {
            $result['exception'] = [$exception::class, $exception->getMessage(), method_exists($exception, 'getResponse') ? $exception->getResponse()->getStatusCode() : null];
        }

        return $result;
    }
}

/** Inner async decorator that reports a redirect count, as a real transport does after following redirects. */
final class RedirectCountClient implements HttpClientInterface
{
    use AsyncDecoratorTrait;

    /** @param array<string, mixed> $info */
    public function __construct(HttpClientInterface $client, private readonly int $count, private readonly array $info = [])
    {
        $this->client = $client;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $info = ['redirect_count' => $this->count] + $this->info;

        return new AsyncResponse($this->client, $method, $url, $options, static function (ChunkInterface $chunk, AsyncContext $context) use ($info): Generator {
            foreach ($info as $key => $value) {
                $context->setInfo($key, $value);
            }

            yield $chunk;
        });
    }
}

/** A chunk whose isLast() fails (observer robustness) or that reports an error without being an ErrorChunk. */
final readonly class BrokenChunk implements ChunkInterface
{
    public function __construct(private ?string $error)
    {
    }

    public function isTimeout(): bool
    {
        return false;
    }

    public function isFirst(): bool
    {
        return false;
    }

    public function isLast(): bool
    {
        throw new RuntimeException('broken chunk');
    }

    public function getInformationalStatus(): ?array
    {
        return null;
    }

    public function getContent(): string
    {
        return '';
    }

    public function getOffset(): int
    {
        return 0;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
