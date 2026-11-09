<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Usage;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\HttpLogBundle\Context\HttpLogContext;
use Nowo\HttpLogBundle\DependencyInjection\NowoHttpLogExtension;
use Nowo\HttpLogBundle\Doctrine\UsageColumnsListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\Tests\Kernel\UsageKernel;
use Nowo\HttpLogBundle\Usage\HttpLogBuffer;
use Nowo\HttpLogBundle\Usage\UsageBufferFlushSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_keys;

/**
 * BC matrix of HL-1 §2 on a booted kernel: a host that enables nothing has no listener, subscriber or middleware of
 * the feature in its compiled container, the 1.14 mapping, and the 1.14 web payload and row; enabling a block keeps
 * web rows and payloads as they were (channel NULL).
 */
final class UsageBcKernelTest extends TestCase
{
    /** Payload keys of a recorded web request in 1.14.0 (golden). */
    private const WEB_PAYLOAD_KEYS = [
        'requestId', 'method', 'scheme', 'host', 'path', 'routeName', 'queryParams', 'statusCode', 'clientIp',
        'contentType', 'bodyContentType', 'durationMs', 'userIdentifier', 'requestHeaders', 'responseHeaders',
        'requestBody', 'responseBody', 'requestBodyTruncated', 'responseBodyTruncated', 'responseBodyStored', 'createdAt',
    ];

    #[Test]
    public function aHostThatEnablesNothingGetsThe114Container(): void
    {
        $kernel = new UsageKernel(['async' => false]);
        $kernel->boot();

        try {
            $container = $this->testContainer($kernel);
            foreach ([UsageColumnsListener::class, HttpLogBuffer::class, UsageBufferFlushSubscriber::class, NowoHttpLogExtension::CONTEXT_MIDDLEWARE] as $id) {
                self::assertFalse($container->has($id), $id);
            }
            self::assertTrue($container->has(HttpLogContext::class), 'inert public API');

            $em = $this->em($container);
            self::assertFalse($em->getClassMetadata(HttpLogEntry::class)->hasField('channel'));
            (new SchemaTool($em))->createSchema([$em->getClassMetadata(HttpLogEntry::class)]);

            $this->handle($kernel, '/hello');
            $rows = $em->getConnection()->fetchAllAssociative('SELECT * FROM nowo_http_log_entry');
            self::assertCount(1, $rows);
            self::assertSame('/hello', $rows[0]['path']);
            self::assertArrayNotHasKey('channel', $rows[0]);
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function withABlockEnabledWebRowsAndPayloadsStayAsIn114(): void
    {
        $messages = [];
        $kernel   = new UsageKernel(['mailer' => ['enabled' => true]], ['mailer' => ['dsn' => 'null://null']]);
        $kernel->boot();

        try {
            $container = $this->testContainer($kernel);
            self::assertTrue($container->has(HttpLogBuffer::class));

            $bus = new class($messages) implements MessageBusInterface {
                /** @param list<object> $messages */
                public function __construct(private array &$messages)
                {
                }

                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    $this->messages[] = $message;

                    return new Envelope($message);
                }
            };
            $container->set('messenger.default_bus', $bus);

            $em = $this->em($container);
            self::assertTrue($em->getClassMetadata(HttpLogEntry::class)->hasField('channel'));
            (new SchemaTool($em))->createSchema([$em->getClassMetadata(HttpLogEntry::class)]);

            $this->handle($kernel, '/hello');
            self::assertCount(1, $messages);
            self::assertInstanceOf(PersistHttpLogMessage::class, $messages[0]);
            self::assertSame(self::WEB_PAYLOAD_KEYS, array_keys($messages[0]->payload));
        } finally {
            $kernel->shutdown();
        }
    }

    #[Test]
    public function withNamedChannelsAndTheRollupWebPayloadsStayAsIn114(): void
    {
        $messages = [];
        $kernel   = new UsageKernel(['channels' => ['api' => ['path_prefixes' => ['/api']]], 'rollup' => ['enabled' => true]], [], null, 'bc-116');
        $kernel->boot();

        try {
            $container = $this->testContainer($kernel);
            $bus       = new class($messages) implements MessageBusInterface {
                /** @param list<object> $messages */
                public function __construct(private array &$messages)
                {
                }

                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    $this->messages[] = $message;

                    return new Envelope($message);
                }
            };
            $container->set('messenger.default_bus', $bus);

            $this->handle($kernel, '/hello');
            self::assertCount(1, $messages, 'rollup.web is off: the web request is only its 1.14 message');
            self::assertInstanceOf(PersistHttpLogMessage::class, $messages[0]);
            self::assertSame(self::WEB_PAYLOAD_KEYS, array_keys($messages[0]->payload));
        } finally {
            $kernel->shutdown();
        }
    }

    private function handle(UsageKernel $kernel, string $path): void
    {
        $request  = Request::create($path);
        $response = $kernel->handle($request);
        self::assertSame(200, $response->getStatusCode());
        $kernel->terminate($request, $response);
    }

    private function testContainer(UsageKernel $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function em(ContainerInterface $container): EntityManagerInterface
    {
        $em = $container->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
