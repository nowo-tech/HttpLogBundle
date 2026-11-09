<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Context;

use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\Context\HttpLogFrame;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

final class HttpLogContextTest extends TestCase
{
    #[Test]
    public function runPushesForTheCallableOnly(): void
    {
        $context = new HttpLogContext();
        self::assertNull($context->current()->tenantKey);

        $seen = $context->run('org-1', static fn (): string => $context->current()->tenantKey . '/' . $context->current()->projectKey, 'p-1');

        self::assertSame('org-1/p-1', $seen);
        self::assertSame(0, $context->depth());
        self::assertNull($context->current()->tenantKey);
    }

    #[Test]
    public function nestingRestoresTheOuterFrameAndKeepsTheMessageId(): void
    {
        $context = new HttpLogContext();
        $context->runFrame(new HttpLogFrame('org-1', null, 'msg-1'), static function () use ($context): void {
            $context->run('org-2', static function () use ($context): void {
                self::assertSame('org-2', $context->current()->tenantKey);
                self::assertSame('msg-1', $context->current()->messengerMessageId);
                self::assertSame(2, $context->depth());
            });
            self::assertSame('org-1', $context->current()->tenantKey);
        });

        self::assertSame(0, $context->depth());
    }

    #[Test]
    public function anExceptionPopsTheFrame(): void
    {
        $context = new HttpLogContext();

        try {
            $context->run('org-1', static function (): never {
                throw new RuntimeException('handler failed');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, $context->depth());
    }

    #[Test]
    public function aFrameLeftBehindByAMisbehavingCallableIsDropped(): void
    {
        $context = new HttpLogContext();
        $context->run('org-1', static function () use ($context): void {
            // a frame pushed and never popped (cannot happen through the API, guarded anyway)
            $frames = new ReflectionProperty(HttpLogContext::class, 'frames');
            $frames->setValue($context, [...$frames->getValue($context), new HttpLogFrame('leak')]);
        });

        self::assertSame(0, $context->depth());
    }

    #[Test]
    public function resetEmptiesTheStack(): void
    {
        $context = new HttpLogContext();
        $frames  = new ReflectionProperty(HttpLogContext::class, 'frames');
        $frames->setValue($context, [new HttpLogFrame('org-1')]);

        $context->reset();
        self::assertSame(0, $context->depth());
    }
}
