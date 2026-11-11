<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\HttpLogBundle\Doctrine\PathPrefixColumnListener;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_keys;
use function mb_strlen;
use function mb_substr;
use function str_repeat;

/** PERF-1147-02: `web_ui.filter_match: prefix` maps path_prefix and its index on the entry table only. */
final class PathPrefixColumnListenerTest extends TestCase
{
    #[Test]
    public function mapsTheColumnAndItsIndexOnce(): void
    {
        $metadata = $this->metadata(HttpLogEntry::class);
        $listener = new PathPrefixColumnListener();

        $listener->loadClassMetadata($this->event($metadata));
        $listener->loadClassMetadata($this->event($metadata));

        self::assertTrue($metadata->hasField(PathPrefixColumnListener::FIELD));
        self::assertSame(PathPrefixColumnListener::COLUMN, $metadata->getColumnName(PathPrefixColumnListener::FIELD));
        self::assertSame(HttpLogEntry::PATH_PREFIX_LENGTH, $metadata->getFieldMapping(PathPrefixColumnListener::FIELD)->length);
        self::assertTrue($metadata->isNullable(PathPrefixColumnListener::FIELD));
        self::assertSame(['idx_http_log_created_at', PathPrefixColumnListener::INDEX], array_keys($metadata->table['indexes'] ?? []));
        self::assertSame(['columns' => [PathPrefixColumnListener::COLUMN, 'created_at']], $metadata->table['indexes'][PathPrefixColumnListener::INDEX]);
    }

    #[Test]
    public function theTableNamingPrefixesTheIndexAfterwards(): void
    {
        $metadata = $this->metadata(HttpLogEntry::class);

        (new PathPrefixColumnListener())->loadClassMetadata($this->event($metadata));
        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($metadata));

        self::assertArrayHasKey('app_' . PathPrefixColumnListener::INDEX, $metadata->table['indexes'] ?? []);
    }

    #[Test]
    public function otherEntitiesAreIgnored(): void
    {
        $metadata = $this->metadata(stdClass::class);

        (new PathPrefixColumnListener())->loadClassMetadata($this->event($metadata));

        self::assertFalse($metadata->hasField(PathPrefixColumnListener::FIELD));
    }

    #[Test]
    public function theEntityKeepsThePrefixOfItsPath(): void
    {
        $long = '/é' . str_repeat('a', 300);

        self::assertNull((new HttpLogEntry())->getPathPrefix());
        self::assertSame('/api', (new HttpLogEntry())->setPath('/api')->getPathPrefix());
        self::assertSame(HttpLogEntry::PATH_PREFIX_LENGTH, mb_strlen((string) (new HttpLogEntry())->setPath($long)->getPathPrefix()));
        self::assertSame('/é', mb_substr(HttpLogEntry::pathPrefixOf($long), 0, 2), 'cut by characters, not bytes');
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable(['name' => 'nowo_http_log_entry', 'indexes' => ['idx_http_log_created_at' => ['columns' => ['created_at']]]]);

        return $metadata;
    }

    /** @param ClassMetadata<object> $metadata */
    private function event(ClassMetadata $metadata): LoadClassMetadataEventArgs
    {
        return new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class));
    }
}
