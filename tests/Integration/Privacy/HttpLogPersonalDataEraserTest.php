<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Privacy;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use Nowo\HttpLogBundle\Privacy\HttpLogPersonalDataEraser;
use Nowo\HttpLogBundle\Repository\HttpLogEntryRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;

use const PHP_VERSION_ID;

/**
 * GDPR erasure API: counts and batched deletes by user identifier and client IP, on a renamed table.
 */
final class HttpLogPersonalDataEraserTest extends TestCase
{
    private EntityManager $entityManager;

    protected function setUp(): void
    {
        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TablePrefixListener('', 'http_log_'));
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $connection          = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->entityManager = new EntityManager($connection, $configuration, $events);
        (new SchemaTool($this->entityManager))->createSchema([$this->entityManager->getClassMetadata(HttpLogEntry::class)]);
        self::assertSame('http_log_entry', $this->entityManager->getClassMetadata(HttpLogEntry::class)->getTableName());

        foreach ([['ana', '10.0.0.1'], ['ana', '10.0.0.2'], ['ana', '10.0.0.1'], ['luis', '10.0.0.1'], [null, '10.0.0.9']] as [$user, $ip]) {
            $entry = new HttpLogEntry();
            $entry->setUserIdentifier($user);
            $entry->setClientIp($ip);
            $this->entityManager->persist($entry);
        }
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    #[Test]
    public function countsAndDeletesByUserIdentifierInBatches(): void
    {
        $eraser = $this->eraser(['purge_batch_size' => 2]);

        self::assertSame(3, $eraser->countByUserIdentifier('ana'));
        self::assertSame(3, $eraser->deleteByUserIdentifier('ana'));
        self::assertSame(0, $eraser->countByUserIdentifier('ana'));
        self::assertSame(1, $eraser->countByUserIdentifier('luis'), 'other users are kept');
        self::assertSame(0, $eraser->deleteByUserIdentifier('nobody'));
    }

    #[Test]
    public function countsAndDeletesByClientIp(): void
    {
        $eraser = $this->eraser([]);

        self::assertSame(3, $eraser->countByClientIp('10.0.0.1'));
        self::assertSame(3, $eraser->deleteByClientIp('10.0.0.1'));
        self::assertSame(0, $eraser->countByClientIp('10.0.0.1'));
        self::assertSame(1, $eraser->countByClientIp('10.0.0.9'));
        self::assertSame(1, $eraser->countByClientIp('10.0.0.2'));
    }

    #[Test]
    public function invalidBatchSizeFallsBackToTheDefault(): void
    {
        self::assertSame(1, $this->eraser(['purge_batch_size' => 0])->deleteByUserIdentifier('luis'));
    }

    #[Test]
    public function emptyIdentifiersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The userIdentifier to erase must not be empty.');

        $this->eraser([])->countByUserIdentifier('');
    }

    /**
     * @param array<string, mixed> $retention
     */
    private function eraser(array $retention): HttpLogPersonalDataEraser
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $registry->method('getManager')->willReturn($this->entityManager);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);

        return new HttpLogPersonalDataEraser(new HttpLogEntryRepository($registry), $retention);
    }
}
