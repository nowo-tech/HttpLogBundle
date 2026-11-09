<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Enum\OutboundErrorKind;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\HttpLogChannel;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function ctype_digit;
use function is_string;
use function random_int;
use function strlen;
use function strtolower;

use const PHP_INT_MAX;

/**
 * Records a request of a named channel (HL-1 §4.1, §6) and, with `rollup.web`, counts a web request (HL-1 §9.1).
 *
 * A named-channel row is an allowlist: server event id (never `X-Request-Id`), method, scheme, host, the route path
 * template (never the raw path), route name, status, `exception` as error kind when the kernel handled one,
 * duration, response size and MIME type, the client IP per `client_ip`, the user identifier per `user`, and the
 * guarded resolver context. Never headers, query string or bodies. Errors (status >= `error_status_min`, or an
 * exception) are always recorded; successes by the channel's `sampling_rate`; an unsampled success is still counted
 * by the rollup. Captures go to {@see HttpLogBuffer}, written after the response is sent. Never throws.
 */
final readonly class InboundRequestRecorder
{
    /** Request attribute set when the kernel handled an exception for the request. */
    public const EXCEPTION_ATTRIBUTE = '_nowo_http_log_exception';

    private LoggerInterface $logger;

    public function __construct(
        private InboundChannelMatcher $matcher,
        private RouteTemplateMap $templates,
        private HttpLogRequestContextResolution $resolution,
        private HttpLogBuffer $buffer,
        private ClockInterface $clock,
        ?LoggerInterface $logger = null,
        private bool $rollupEnabled = false,
        private bool $rollupWeb = false,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /** The named channel of the request, or null (also when matching fails). */
    public function match(Request $request): ?InboundChannel
    {
        try {
            return $this->matcher->match($request);
        } catch (Throwable $exception) {
            $this->warn($exception);

            return null;
        }
    }

    /** True with `rollup.enabled` and `rollup.web`: web requests are counted (Q-O6). */
    public function countsWeb(): bool
    {
        return $this->rollupEnabled && $this->rollupWeb;
    }

    public function record(InboundChannel $channel, Request $request, Response $response, float $durationMs): void
    {
        try {
            $error = $request->attributes->get(self::EXCEPTION_ATTRIBUTE) === true;
            $row   = $error || $response->getStatusCode() >= $channel->errorStatusMin || $this->sampled($channel->samplingRate);
            $count = $this->rollupEnabled && $channel->rollup;
            if (!$row && !$count) {
                return;
            }

            $context = $this->resolution->forRequest($request, $channel->name);
            $route   = $request->attributes->get('_route');
            $route   = is_string($route) && $route !== '' ? $route : null;

            // @igor-ignore - HttpLogBuffer is its own kernel.reset service, flushed at every request boundary
            $this->buffer->add(new UsageCapture(
                channel: $channel->name,
                createdAt: DateTimeImmutable::createFromInterface($this->clock->now()),
                method: $request->getMethod(),
                path: $this->templates->template($route),
                scheme: $request->getScheme(),
                host: self::host($request),
                statusCode: $response->getStatusCode(),
                errorKind: $error ? OutboundErrorKind::Exception->value : null,
                durationMs: $durationMs,
                responseBytes: $this->bytes($response),
                contentType: $response->headers->get('Content-Type'),
                tenantKey: $context->tenantKey,
                projectKey: $context->projectKey,
                routeName: $route,
                // a count-only event carries no personal data: the rollup keeps neither IP nor user
                clientIp: $row ? ClientIpTruncator::apply($request->getClientIp(), $channel->clientIp) : null,
                userIdentifier: $row && $channel->user ? $this->resolution->userIdentifier($request) : null,
                credentialKind: $context->credentialKind,
                credentialId: $context->credentialId,
                row: $row,
                rollup: $count,
            ));
        } catch (Throwable $exception) {
            $this->warn($exception);
        }
    }

    /** A count-only event of the web channel (its row, if sampled, keeps the 1.14 path). */
    public function countWeb(Request $request, Response $response, float $durationMs): void
    {
        try {
            // @igor-ignore - HttpLogBuffer is its own kernel.reset service, flushed at every request boundary
            $this->buffer->add(new UsageCapture(
                channel: HttpLogChannel::WEB,
                createdAt: DateTimeImmutable::createFromInterface($this->clock->now()),
                method: $request->getMethod(),
                statusCode: $response->getStatusCode(),
                errorKind: $request->attributes->get(self::EXCEPTION_ATTRIBUTE) === true ? OutboundErrorKind::Exception->value : null,
                durationMs: $durationMs,
                responseBytes: $this->bytes($response),
                row: false,
                rollup: true,
            ));
        } catch (Throwable $exception) {
            $this->warn($exception);
        }
    }

    /** Lower-cased host and port; NULL when `trusted_hosts` rejects the Host header, so that 400 is still recorded (JA-I4). */
    private static function host(Request $request): ?string
    {
        try {
            return strtolower($request->getHttpHost());
        } catch (SuspiciousOperationException) {
            return null;
        }
    }

    /** `Content-Length`, else the length of a body that is not streamed. */
    private function bytes(Response $response): ?int
    {
        $length = $response->headers->get('Content-Length');
        if (is_string($length) && $length !== '' && strlen($length) <= 18 && ctype_digit($length)) {
            return (int) $length;
        }
        $content = $response->getContent();

        return is_string($content) ? strlen($content) : null;
    }

    private function sampled(float $rate): bool
    {
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return random_int(0, PHP_INT_MAX) / PHP_INT_MAX <= $rate;
    }

    private function warn(Throwable $exception): void
    {
        $this->logger->warning('HTTP log inbound capture dropped.', ['exception_class' => $exception::class]);
    }
}
