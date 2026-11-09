<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\HttpClient;

use Closure;
use DateTimeImmutable;
use Generator;
use Nowo\HttpLogBundle\Context\HttpLogFrame;
use Nowo\HttpLogBundle\Enum\OutboundErrorKind;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\HttpLogChannel;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

use function array_filter;
use function hrtime;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_numeric;
use function is_string;
use function str_starts_with;
use function stripos;
use function strlen;
use function substr;
use function trim;

/**
 * State of one outbound call, owned by its response's passthru closure (never by the decorator service): records the
 * call **exactly once** at the first of last chunk, fatal error chunk, `cancel()` or destruct (HL-1 §5.1). Chunks are
 * forwarded untouched; the body is never read or buffered.
 */
final class OutboundCall
{
    private bool $done = false;

    /** The last chunk seen was an idle timeout: if the response ends (destruct) before more data, it timed out. */
    private bool $timedOut = false;

    private readonly int $startedAt;

    /**
     * @param OutboundTarget|null $target null while the request URL is relative (scoped client with a base_uri: this
     *                                    decorator is outside the scoping client), resolved from the response's
     *                                    `original_url` when the call is recorded
     * @param array{url: string, clientId: string|null, clientProvider: string|null, requestProvider: mixed} $resolve
     */
    public function __construct(
        private readonly ?OutboundTarget $target,
        private readonly array $resolve,
        private readonly string $method,
        private readonly DateTimeImmutable $createdAt,
        private readonly HttpLogFrame $frame,
        private readonly ?string $tenantKey,
        private readonly ?string $projectKey,
        private readonly int $maxRedirects,
        private readonly OutboundCapturePolicy $policy,
        private readonly HttpLogBuffer $buffer,
        private readonly LoggerInterface $logger,
    ) {
        $this->startedAt = hrtime(true);
    }

    /** @return Closure(ChunkInterface, AsyncContext): Generator<int, ChunkInterface> */
    public function passthru(): Closure
    {
        return function (ChunkInterface $chunk, AsyncContext $context): Generator {
            $this->observe($chunk, $context);

            yield $chunk;
        };
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    /**
     * Records a call whose `request()` threw before any response existed (invalid option, synchronous transport
     * error); the caller rethrows the same exception.
     */
    public function failed(Throwable $error): void
    {
        $this->record([], OutboundErrorClassifier::classify(null, $error));
    }

    private function observe(ChunkInterface $chunk, AsyncContext $context): void
    {
        if ($this->done) {
            return;
        }

        try {
            if ($chunk->getError() !== null) {
                $fatal = $this->fatal($chunk);
                if ($fatal === false) {
                    // @igor-ignore - per-call state owned by the response passthru closure; OutboundCall is never a container service
                    $this->timedOut = true; // recoverable: the caller may keep streaming, or give up

                    return;
                }
                $this->record($context->getInfo(), OutboundErrorClassifier::classify($chunk->getError(), $fatal));

                return;
            }

            if (!$chunk->isLast()) {
                // @igor-ignore - per-call state owned by the response passthru closure; OutboundCall is never a container service
                $this->timedOut = false;

                return;
            }

            $info = $context->getInfo();
            $kind = null;
            if (($info['canceled'] ?? false) === true) {
                // cancel() sets an error before the last chunk; a destruct sets none (after a timeout the caller gave up)
                $kind = is_string($info['error'] ?? null) ? OutboundErrorKind::Cancelled : ($this->timedOut ? OutboundErrorKind::Timeout : null);
            }
            $this->record($info, $kind);
        } catch (Throwable $exception) {
            // @igor-ignore - per-call state owned by the response passthru closure; OutboundCall is never a container service
            $this->done = true;
            $this->logger->warning('HTTP log outbound capture dropped.', ['exception_class' => $exception::class]);
        }
    }

    /**
     * false for a recoverable timeout chunk (the caller may keep streaming), else the exception behind the error (or
     * null when unknown). The chunk's "thrown" state is restored, so its own behaviour is unchanged.
     */
    private function fatal(ChunkInterface $chunk): Throwable|false|null
    {
        if (!$chunk instanceof ErrorChunk) {
            return null; // HttpClient only reports errors through ErrorChunk
        }

        $didThrow = $chunk->didThrow();

        try {
            $chunk->isTimeout();

            return false;
        } catch (TransportExceptionInterface $exception) {
            return $exception->getPrevious();
        } finally {
            $chunk->didThrow($didThrow);
        }
    }

    /** @param array<string, mixed> $info */
    private function record(array $info, ?OutboundErrorKind $kind): void
    {
        // @igor-ignore - per-call state owned by the response passthru closure; OutboundCall is never a container service
        $this->done = true;

        try {
            $target = $this->target ?? $this->policy->target(
                is_string($info['original_url'] ?? null) ? $info['original_url'] : $this->resolve['url'],
                $this->resolve['clientId'],
                $this->resolve['clientProvider'],
                $this->resolve['requestProvider'],
            );
            if (!$target instanceof OutboundTarget) {
                return; // unmapped host with record_unmapped: false
            }

            $status = is_int($info['http_code'] ?? null) && $info['http_code'] > 0 ? $info['http_code'] : null;
            $kind ??= $this->redirectLimit($info, $status) ?? OutboundErrorKind::fromStatus($status);
            if (!in_array($kind, [null, OutboundErrorKind::Http4xx, OutboundErrorKind::Http5xx], true)) {
                $status = null; // transport error: no final status
            }

            $capture = new UsageCapture(
                channel: HttpLogChannel::OUTBOUND,
                createdAt: $this->createdAt,
                method: $this->method,
                path: $target->path,
                provider: $target->provider,
                scheme: $target->scheme,
                host: $target->host,
                statusCode: $status,
                errorKind: $kind?->value,
                durationMs: (hrtime(true) - $this->startedAt) / 1e6,
                responseBytes: $this->bytes($info['size_download'] ?? null),
                retryCount: is_int($info['retry_count'] ?? null) ? $info['retry_count'] : 0,
                contentType: $this->contentType($info),
                tenantKey: $this->tenantKey ?? $this->frame->tenantKey,
                projectKey: $this->projectKey ?? $this->frame->projectKey,
                messengerMessageId: $this->frame->messengerMessageId,
            );

            if ($this->policy->keep($target, $capture->isError())) {
                // @igor-ignore - per-call state owned by the response passthru closure; OutboundCall is never a container service
                $this->buffer->add($capture);
            }
        } catch (Throwable $exception) {
            $this->logger->warning('HTTP log outbound capture dropped.', ['exception_class' => $exception::class]);
        }
    }

    /**
     * A final 3xx with a Location after `max_redirects` redirects: the client stopped following.
     *
     * @param array<string, mixed> $info
     */
    private function redirectLimit(array $info, ?int $status): ?OutboundErrorKind
    {
        $redirects = $info['redirect_count'] ?? 0;
        if ($status === null || $status < 300 || $status >= 400 || $status === 304 || $this->maxRedirects < 1 || !is_int($redirects) || $redirects < $this->maxRedirects) {
            return null;
        }

        return $this->header($info, 'location') !== null ? OutboundErrorKind::RedirectLimit : null;
    }

    private function bytes(mixed $size): ?int
    {
        if (is_int($size)) {
            return $size;
        }

        return is_float($size) || (is_string($size) && is_numeric($size)) ? (int) $size : null;
    }

    /** @param array<string, mixed> $info */
    private function contentType(array $info): ?string
    {
        $type = $info['content_type'] ?? null;

        return is_string($type) && $type !== '' ? $type : $this->header($info, 'content-type');
    }

    /**
     * Last value of a response header from the raw `response_headers` info (headers of the final response).
     *
     * @param array<string, mixed> $info
     */
    private function header(array $info, string $name): ?string
    {
        $lines = $info['response_headers'] ?? null;
        if (!is_array($lines)) {
            return null;
        }

        $value  = null;
        $prefix = $name . ':';
        foreach (array_filter($lines, is_string(...)) as $line) {
            if (str_starts_with($line, 'HTTP/')) {
                $value = null; // a new response (redirect, 100 Continue) starts
            } elseif (stripos($line, $prefix) === 0) {
                $value = trim(substr($line, strlen($prefix)));
            }
        }

        return $value;
    }
}
