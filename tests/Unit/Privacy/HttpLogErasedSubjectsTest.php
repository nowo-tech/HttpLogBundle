<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Privacy;

use Nowo\HttpLogBundle\Privacy\HttpLogErasedSubjects;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataSubject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * LY-L2: the request-scoped skip list matches exactly, and nothing of it survives a boundary.
 */
final class HttpLogErasedSubjectsTest extends TestCase
{
    #[Test]
    public function erasedValuesMatchExactly(): void
    {
        $erased = new HttpLogErasedSubjects();
        self::assertTrue($erased->isEmpty());
        self::assertFalse($erased->matches('ana', '10.0.0.1'));

        $erased->remember(HttpLogPersonalDataSubject::fromUserIdentifier('ana'));
        $erased->remember(HttpLogPersonalDataSubject::fromClientIp('10.0.0.1'));
        $erased->remember(HttpLogPersonalDataSubject::fromUserIdentifier('123'));

        self::assertFalse($erased->isEmpty());
        self::assertTrue($erased->matches('ana', null));
        self::assertTrue($erased->matches(null, '10.0.0.1'));
        self::assertTrue($erased->matches('123', null), 'numeric identifiers too');
        self::assertFalse($erased->matches('Ana', null), 'no case folding');
        self::assertFalse($erased->matches('ána', null));
        self::assertFalse($erased->matches('10.0.0.1', null), 'an IP value only matches the IP column');
        self::assertFalse($erased->matches(null, 'ana'));
        self::assertFalse($erased->matches(null, null));
        self::assertFalse($erased->dropsCurrentRequest());
    }

    #[Test]
    public function theCurrentRequestFlagAndTheResetAtEveryBoundary(): void
    {
        $erased = new HttpLogErasedSubjects();
        $erased->dropCurrentRequest();
        self::assertTrue($erased->dropsCurrentRequest());
        self::assertFalse($erased->isEmpty());
        $erased->reset();
        self::assertTrue($erased->isEmpty());

        $kernel = $this->createStub(HttpKernelInterface::class);
        $erased->remember(HttpLogPersonalDataSubject::fromUserIdentifier('ana'));
        $erased->onRequest(new RequestEvent($kernel, Request::create('/sub'), HttpKernelInterface::SUB_REQUEST));
        self::assertTrue($erased->matches('ana', null), 'a sub-request is part of the same boundary');
        $erased->onRequest(new RequestEvent($kernel, Request::create('/next'), HttpKernelInterface::MAIN_REQUEST));
        self::assertTrue($erased->isEmpty(), 'a new main request starts empty');

        $events = HttpLogErasedSubjects::getSubscribedEvents();
        self::assertSame(['onRequest', 4096], $events[KernelEvents::REQUEST]);
        foreach ([KernelEvents::TERMINATE, 'console.terminate', WorkerMessageHandledEvent::class, WorkerMessageFailedEvent::class] as $event) {
            self::assertSame(['reset', -4096], $events[$event], $event . ': after the buffer flush (-2048) and the web capture (-1024)');
        }
    }

    #[Test]
    public function beyondTheCapacityEveryCaptureOfTheBoundaryIsDropped(): void
    {
        $erased = new HttpLogErasedSubjects();
        for ($i = 0; $i < HttpLogErasedSubjects::CAPACITY; ++$i) {
            $erased->remember(HttpLogPersonalDataSubject::fromUserIdentifier('user-' . $i));
        }
        self::assertFalse($erased->matches('someone-else', null));

        $erased->remember(HttpLogPersonalDataSubject::fromUserIdentifier('one-too-many'));
        self::assertTrue($erased->matches('someone-else', null), 'fail closed');
        self::assertTrue($erased->dropsCurrentRequest());

        $erased->reset();
        self::assertFalse($erased->matches('someone-else', null));
    }
}
