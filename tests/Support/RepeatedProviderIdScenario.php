<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use Nowo\HttpLogBundle\Correlation\CorrelationIds;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\MessageHandler\PersistHttpLogHandler;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use PHPUnit\Framework\Assert;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * ZDW-HL-M1: two main requests whose host provider returns the same id are both stored, sync or async, with distinct
 * `request_id` and the same `correlation_id`.
 */
final class RepeatedProviderIdScenario
{
    /**
     * @return list<array<string, mixed>> the stored rows
     */
    public static function run(UsageTestDatabase $db, bool $async, HttpKernelInterface $kernel): array
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<PersistHttpLogMessage> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                Assert::assertInstanceOf(PersistHttpLogMessage::class, $message);
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
        $config   = ['request_headers' => false, 'response_headers' => false, 'request_body' => false, 'client_ip' => false, 'user' => false, 'max_body_bytes' => 1024];
        $logger   = new CollectingLogger();
        $recorder = new HttpLogRecorder($db->entityManager, $bus, new MockClock(), $logger, new HttpLogRedactor([], [], []), new CapturePolicy($config), new ContentTypeClassifier(), $async, $config, managerRegistry: $db);
        $provider = new MutableCorrelationIdProvider('chain-1');
        $sub      = new HttpLogSubscriber($recorder, true, [], 1.0, false, [], [], 'test', correlation: new CorrelationIds($provider));

        foreach (['/a', '/b'] as $path) {
            $request = Request::create($path);
            $sub->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
            $sub->onKernelResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
            $sub->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok')));
        }

        $handler = new PersistHttpLogHandler($db->repository(), $recorder, $logger);
        foreach ($bus->messages as $message) {
            $handler($message);
        }
        Assert::assertSame([], $logger->records, 'no row was dropped');

        return $db->rows();
    }
}
