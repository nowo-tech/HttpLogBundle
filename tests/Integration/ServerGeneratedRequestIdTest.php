<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\EventSubscriber\HttpLogSubscriber;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_map;
use function dirname;

use const PHP_VERSION_ID;

/**
 * Regression: the stored request_id is the dedupe key of a log row, so it must never come from
 * the client. Two requests sending the same X-Request-Id must produce two rows.
 */
final class ServerGeneratedRequestIdTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);

        $this->entityManager = new EntityManager($connection, $configuration);
        (new SchemaTool($this->entityManager))->createSchema([
            $this->entityManager->getClassMetadata(HttpLogEntry::class),
        ]);
    }

    #[Test]
    public function sameClientRequestIdOnTwoRequestsProducesTwoRows(): void
    {
        $subscriber = $this->createSubscriber();

        $this->handle($subscriber, '/api/first', 'attacker-chosen-id');
        $this->handle($subscriber, '/api/second', 'attacker-chosen-id');

        /** @var list<HttpLogEntry> $entries */
        $entries = $this->entityManager->createQueryBuilder()
            ->select('e')->from(HttpLogEntry::class, 'e')->orderBy('e.id', 'ASC')
            ->getQuery()->getResult();

        self::assertCount(2, $entries);
        self::assertSame(['/api/first', '/api/second'], array_map(static fn (HttpLogEntry $e): string => $e->getPath(), $entries));

        $ids = array_map(static fn (HttpLogEntry $e): ?string => $e->getRequestId(), $entries);
        self::assertNotSame($ids[0], $ids[1]);
        foreach ($ids as $id) {
            self::assertNotSame('attacker-chosen-id', $id);
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $id);
        }

        // The client header is still available for correlation, in the captured request headers only.
        foreach ($entries as $entry) {
            self::assertSame(['attacker-chosen-id'], $entry->getRequestHeaders()['x-request-id'] ?? null);
        }
    }

    private function handle(HttpLogSubscriber $subscriber, string $path, string $clientRequestId): void
    {
        $kernel  = $this->createMock(HttpKernelInterface::class);
        $request = Request::create($path);
        $request->attributes->set('_route', 'api_route');
        $request->headers->set('X-Request-Id', $clientRequestId);

        $subscriber->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok')));
    }

    private function createSubscriber(): HttpLogSubscriber
    {
        $captureConfig = [
            'request_headers'  => true,
            'response_headers' => true,
            'request_body'     => false,
            'client_ip'        => true,
            'user'             => false,
            'max_body_bytes'   => 65536,
        ];

        $recorder = new HttpLogRecorder(
            $this->entityManager,
            $this->createMock(MessageBusInterface::class),
            new MockClock(new DateTimeImmutable('2026-11-09T10:00:00+00:00')),
            new NullLogger(),
            new HttpLogRedactor([], [], []),
            new CapturePolicy($captureConfig),
            new ContentTypeClassifier(),
            false,
            $captureConfig,
        );

        return new HttpLogSubscriber($recorder, true, [], 1.0, false, [], [], 'test');
    }
}
