<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Message\PersistHttpLogMessage;
use Nowo\HttpLogBundle\MessageHandler\PersistHttpLogHandler;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use Nowo\HttpLogBundle\Service\CapturePolicy;
use Nowo\HttpLogBundle\Service\ContentTypeClassifier;
use Nowo\HttpLogBundle\Service\HttpLogRecorder;
use Nowo\HttpLogBundle\Service\HttpLogRedactor;
use Nowo\HttpLogBundle\Tests\Fixtures\Entity\HostArticle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function dirname;

use const PHP_VERSION_ID;

/**
 * Rule 6: the bundle shares the host's default EntityManager in a standard install. Recording a request with
 * `async: false` (or handling the message on a sync transport) runs at kernel.terminate, after the host controller,
 * and must never commit what the host left unflushed (e.g. an entity bound by a form that failed validation).
 */
final class HostEntityManagerIsolationTest extends TestCase
{
    private EntityManager $entityManager;

    private MockObject $registry;

    protected function setUp(): void
    {
        $configuration = ORMSetup::createAttributeMetadataConfiguration(
            [dirname(__DIR__, 2) . '/src/Entity', dirname(__DIR__, 2) . '/tests/Fixtures/Entity'],
            true,
        );
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $this->entityManager = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration), $configuration);
        (new SchemaTool($this->entityManager))->createSchema([
            $this->entityManager->getClassMetadata(HttpLogEntry::class),
            $this->entityManager->getClassMetadata(HostArticle::class),
        ]);

        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->registry->method('getManagerForClass')->willReturn($this->entityManager);
        $this->registry->expects(self::never())->method('resetManager');
    }

    #[Test]
    public function aSyncCaptureDoesNotCommitTheHostsPendingChanges(): void
    {
        $article = $this->hostLeavesPendingChanges();

        $this->recorder(async: false)->record(Request::create('/articles/1/edit', 'POST'), new Response('invalid', 422), 3.0, 'req-invalid-form');

        $this->assertHostChangesStayPending($article);
        self::assertSame(1, $this->countRows('nowo_http_log_entry'));
    }

    #[Test]
    public function aSyncTransportHandlerDoesNotCommitTheHostsPendingChanges(): void
    {
        $dispatched = [];
        $bus        = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (PersistHttpLogMessage $message) use (&$dispatched): Envelope {
            $dispatched[] = $message;

            return new Envelope($message);
        });
        $this->recorder(async: true, bus: $bus)->record(Request::create('/articles/1/edit', 'POST'), new Response('invalid', 422), 3.0, 'req-sync-transport');
        self::assertCount(1, $dispatched);

        $article = $this->hostLeavesPendingChanges();
        $handler = new PersistHttpLogHandler(new HttpLogEntryRepository($this->registry), $this->recorder(async: false), new NullLogger());
        $handler($dispatched[0]);
        $handler($dispatched[0]); // redelivered: deduplicated on the request id

        $this->assertHostChangesStayPending($article);
        self::assertSame(1, $this->countRows('nowo_http_log_entry'));
    }

    #[Test]
    public function aClosedSharedManagerIsNeitherResetNorNeededToRecord(): void
    {
        $this->entityManager->close();

        $this->recorder(async: false)->record(Request::create('/after-host-failure'), new Response('error', 500), 1.0, 'req-closed');

        self::assertSame(1, $this->countRows('nowo_http_log_entry'));
        self::assertSame(1, (new HttpLogEntryRepository($this->registry))->countAll());
        self::assertFalse($this->entityManager->isOpen(), 'the host decides when its manager is reset');
    }

    /** An existing article edited and a new one persisted, nothing flushed: what an invalid form leaves behind. */
    private function hostLeavesPendingChanges(): HostArticle
    {
        $article = new HostArticle('Original');
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        $article->setTitle('Edited by an invalid form');
        $this->entityManager->persist(new HostArticle('Never submitted'));

        return $article;
    }

    private function assertHostChangesStayPending(HostArticle $article): void
    {
        self::assertSame(
            [['title' => 'Original']],
            $this->entityManager->getConnection()->fetchAllAssociative('SELECT title FROM host_article ORDER BY id'),
        );
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        self::assertSame(['Original', 'Edited by an invalid form'], $unitOfWork->getEntityChangeSet($article)['title']);
        self::assertCount(1, $unitOfWork->getScheduledEntityInsertions());
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function recorder(bool $async, ?MessageBusInterface $bus = null): HttpLogRecorder
    {
        $captureConfig = ['request_headers' => true, 'response_headers' => true, 'request_body' => true, 'client_ip' => true, 'user' => false, 'max_body_bytes' => 65536];

        return new HttpLogRecorder(
            $this->entityManager,
            $bus ?? $this->createMock(MessageBusInterface::class),
            new MockClock(new DateTimeImmutable('2026-11-10T10:00:00+00:00')),
            new NullLogger(),
            new HttpLogRedactor([], [], []),
            new CapturePolicy($captureConfig),
            new ContentTypeClassifier(),
            $async,
            $captureConfig,
            null,
            $this->registry,
        );
    }
}
