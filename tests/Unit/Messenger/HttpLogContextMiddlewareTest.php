<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Messenger;

use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Context\HttpLogFrame;
use Nowo\HttpLogBundle\Message\PurgeHttpLogMessage;
use Nowo\HttpLogBundle\Messenger\HttpLogContextMiddleware;
use Nowo\HttpLogBundle\Messenger\HttpLogContextStamp;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

final class HttpLogContextMiddlewareTest extends TestCase
{
    #[Test]
    public function dispatchAddsOneStampWithTheTenantInScope(): void
    {
        $context    = new HttpLogContext();
        $middleware = new HttpLogContextMiddleware($context, true, new NullLogger());

        $envelope = $context->run('org-1', fn (): Envelope => $middleware->handle(new Envelope(new stdClass()), $this->stack()), 'p-1');
        $stamp    = $envelope->last(HttpLogContextStamp::class);

        self::assertInstanceOf(HttpLogContextStamp::class, $stamp);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $stamp->messageId);
        self::assertSame('org-1', $stamp->tenantKey);
        self::assertSame('p-1', $stamp->projectKey);

        // a retry re-dispatches the same envelope: the id stays, no second stamp
        $again = $middleware->handle($envelope, $this->stack());
        self::assertCount(1, $again->all(HttpLogContextStamp::class));
        self::assertSame($stamp->messageId, $again->last(HttpLogContextStamp::class)?->messageId);
    }

    #[Test]
    public function tenantIsNotPropagatedWhenDisabled(): void
    {
        $context    = new HttpLogContext();
        $middleware = new HttpLogContextMiddleware($context, false);

        $envelope = $context->run('org-1', fn (): Envelope => $middleware->handle(new Envelope(new stdClass()), $this->stack()));

        self::assertNull($envelope->last(HttpLogContextStamp::class)?->tenantKey);
    }

    #[Test]
    public function handlingPushesTheStampFrameAndPopsIt(): void
    {
        $context    = new HttpLogContext();
        $middleware = new HttpLogContextMiddleware($context);
        $seen       = null;
        $envelope   = (new Envelope(new stdClass(), [new HttpLogContextStamp('0123456789abcdef0123456789abcdef', 'org-9', 'p-9')]))->with(new ReceivedStamp('async'));

        $middleware->handle($envelope, $this->stack(static function () use ($context, &$seen): void {
            $seen = $context->current();
        }));

        self::assertEquals(new HttpLogFrame('org-9', 'p-9', '0123456789abcdef0123456789abcdef'), $seen);
        self::assertSame(0, $context->depth());
    }

    #[Test]
    public function aFailingHandlerLeavesNoFrame(): void
    {
        $context    = new HttpLogContext();
        $middleware = new HttpLogContextMiddleware($context);
        $envelope   = (new Envelope(new stdClass()))->with(new ReceivedStamp('async'), new TransportMessageIdStamp(77));

        try {
            $middleware->handle($envelope, $this->stack(static function () use ($context): never {
                self::assertSame('77', $context->current()->messengerMessageId);

                throw new RuntimeException('handler failed');
            }));
        } catch (RuntimeException) {
        }

        self::assertSame(0, $context->depth());
    }

    #[Test]
    public function handlingWithoutAnyIdPushesAnEmptyFrame(): void
    {
        $context    = new HttpLogContext();
        $middleware = new HttpLogContextMiddleware($context);
        $seen       = false;

        $middleware->handle((new Envelope(new stdClass()))->with(new ReceivedStamp('async')), $this->stack(static function () use ($context, &$seen): void {
            $seen = $context->depth() === 1 && $context->current()->messengerMessageId === null;
        }));

        self::assertTrue($seen);
    }

    #[Test]
    public function theBundleOwnMessagesGetNoStampAndNoFrame(): void
    {
        $context    = new HttpLogContext();
        $middleware = new HttpLogContextMiddleware($context);
        $depth      = null;

        $out = $context->run('org-1', fn (): Envelope => $middleware->handle(new Envelope(new PurgeHttpLogMessage(null)), $this->stack()));
        self::assertNull($out->last(HttpLogContextStamp::class));

        $middleware->handle((new Envelope(new PurgeHttpLogMessage(null)))->with(new ReceivedStamp('async')), $this->stack(static function () use ($context, &$depth): void {
            $depth = $context->depth();
        }));
        self::assertSame(0, $depth);
    }

    #[Test]
    public function aStampingFailureIsLoggedAndTheMessageStillDispatched(): void
    {
        $context = $this->createMock(HttpLogContext::class);
        $context->method('current')->willThrowException(new RuntimeException('broken'));
        $middleware = new HttpLogContextMiddleware($context);

        $out = $middleware->handle(new Envelope(new stdClass()), $this->stack());

        self::assertNull($out->last(HttpLogContextStamp::class));
    }

    /** @param (callable(): void)|null $handler */
    private function stack(?callable $handler = null): StackInterface
    {
        $next = new class($handler) implements MiddlewareInterface {
            /** @param (callable(): void)|null $handler */
            public function __construct(private $handler)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                if ($this->handler !== null) {
                    ($this->handler)();
                }

                return $envelope;
            }
        };

        return new StackMiddleware($next);
    }
}
