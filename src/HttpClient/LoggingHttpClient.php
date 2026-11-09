<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\HttpClient;

use Closure;
use DateTimeImmutable;
use Generator;
use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\UsageFieldGuard;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_key_exists;
use function array_key_first;
use function array_values;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function parse_url;
use function spl_object_id;

use const PHP_URL_HOST;

/**
 * Outbound HttpClient capture (HL-1 §5.1). Decorates a client (outermost, so one row per logical call after retries)
 * and returns an {@see AsyncResponse} whose passthru forwards every chunk untouched and records the call once.
 *
 * It never changes options (except removing its own `extra.nowo_http_log`), headers, timeouts, redirects, chunk
 * order, exceptions or laziness, and never reads the body. Its own per-request options:
 * `['extra' => ['nowo_http_log' => ['tenant_key' => …, 'project_key' => …, 'provider' => …, 'skip' => true]]]`.
 *
 * Stateless apart from the decorated client (the `DecoratorTrait` property, swapped in clones by `withOptions()`):
 * the state of a call lives in its response's passthru closure.
 */
final class LoggingHttpClient implements HttpClientInterface, ResetInterface
{
    use AsyncDecoratorTrait;

    public const OPTION = 'nowo_http_log';

    private readonly LoggerInterface $logger;

    /**
     * @param string|null $clientId service id of the decorated client (provider map `clients`)
     * @param string|null $provider provider of a wrapped client ({@see HttpLogHttpClientWrapper::wrap()})
     */
    public function __construct(
        HttpClientInterface $client,
        private readonly OutboundCapturePolicy $policy,
        private readonly HttpLogBuffer $buffer,
        private readonly HttpLogContext $context,
        private readonly ClockInterface $clock,
        private readonly ?string $clientId = null,
        private readonly ?string $provider = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->client = $client;
        $this->logger = $logger ?? new NullLogger();
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $own = null;
        if (isset($options['extra']) && is_array($options['extra']) && array_key_exists(self::OPTION, $options['extra'])) {
            $own = $options['extra'][self::OPTION];
            unset($options['extra'][self::OPTION]);
            if ($options['extra'] === []) {
                unset($options['extra']);
            }
        }

        $call = $this->begin($method, $url, is_array($own) ? $own : [], $options);
        if (!$call instanceof OutboundCall) {
            return new AsyncResponse($this->client, $method, $url, $options);
        }

        try {
            return new AsyncResponse($this->client, $method, $url, $options, $call->passthru());
        } catch (Throwable $exception) {
            $call->failed($exception);

            throw $exception;
        }
    }

    /**
     * Like {@see AsyncDecoratorTrait::stream()}, but responses of several decorated clients (a scoped client and
     * `http_client` in one `stream()` call, IX-L2) are streamed one client after the other instead of failing with
     * "Cannot stream AsyncResponse objects with many clients": the bare clients accept that call too.
     */
    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        $groups = [];
        foreach ($responses as $response) {
            if (!$response instanceof AsyncResponse) {
                // same TypeError as AsyncDecoratorTrait
                return new ResponseStream(AsyncResponse::stream($responses, $timeout, self::class));
            }
            $client                                                                        = $this->innerClient($response);
            $groups[$client instanceof HttpClientInterface ? spl_object_id($client) : 0][] = $response;
        }

        if (count($groups) < 2) {
            return new ResponseStream(AsyncResponse::stream($groups[array_key_first($groups)] ?? [], $timeout, self::class));
        }

        // finished responses (no client left) are streamed with the first live group
        $done = $groups[0] ?? [];
        unset($groups[0]);
        $groups = array_values($groups);
        if ($done !== []) {
            $groups[0] = [...$done, ...$groups[0]];
        }

        return new ResponseStream((static function () use ($groups, $timeout): Generator {
            foreach ($groups as $group) {
                yield from AsyncResponse::stream($group, $timeout, self::class);
            }
        })());
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    /** The client an AsyncResponse streams through (null once it completed). */
    private function innerClient(AsyncResponse $response): ?HttpClientInterface
    {
        /** @var Closure(AsyncResponse): ?HttpClientInterface $read */
        $read = Closure::bind(static fn (AsyncResponse $r): ?HttpClientInterface => $r->client, null, AsyncResponse::class);

        return $read($response);
    }

    /**
     * @param array<mixed> $own `extra.nowo_http_log`
     * @param array<string, mixed> $options request options (after removing `extra.nowo_http_log`)
     */
    private function begin(string $method, string $url, array $own, array $options): ?OutboundCall
    {
        if (($own['skip'] ?? false) === true || $this->buffer->isSuspended()) {
            return null;
        }

        try {
            $resolve = ['url' => $url, 'clientId' => $this->clientId, 'clientProvider' => $this->provider, 'requestProvider' => $own['provider'] ?? null];
            $target  = null;
            if (is_string(parse_url($url, PHP_URL_HOST))) {
                $target = $this->policy->target($url, $this->clientId, $this->provider, $resolve['requestProvider']);
                if (!$target instanceof OutboundTarget) {
                    return null;
                }
            }

            return new OutboundCall(
                $target,
                $resolve,
                $method,
                DateTimeImmutable::createFromInterface($this->clock->now()),
                // snapshot at request time: the response may complete later, under another frame
                $this->context->current(),
                UsageFieldGuard::token($own['tenant_key'] ?? null),
                UsageFieldGuard::token($own['project_key'] ?? null),
                is_int($options['max_redirects'] ?? null) ? $options['max_redirects'] : HttpClientInterface::OPTIONS_DEFAULTS['max_redirects'],
                $this->policy,
                $this->buffer,
                $this->logger,
            );
        } catch (Throwable $exception) {
            $this->logger->warning('HTTP log outbound capture dropped.', ['exception_class' => $exception::class]);

            return null;
        }
    }
}
