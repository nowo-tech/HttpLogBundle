<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\HttpLogBundle\Doctrine\TablePrefixListener;
use Nowo\HttpLogBundle\Entity\HttpLogEntry;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TablePrefixListenerTest extends TestCase
{
    public function testEmptyPrefixLeavesNamesUnchanged(): void
    {
        $metadata = $this->metadata(HttpLogEntry::class);

        (new TablePrefixListener(''))->loadClassMetadata($this->event($metadata));

        $this->assertSame('nowo_http_log_entry', $metadata->getTableName());
        $this->assertSame(['idx_http_log_route_name', 'idx_http_log_created_at'], array_keys($metadata->table['indexes'] ?? []));
    }

    public function testHostEntitiesAreIgnored(): void
    {
        $metadata = $this->metadata(stdClass::class);

        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($metadata));

        $this->assertSame('nowo_http_log_entry', $metadata->getTableName());
    }

    public function testBundleTableIndexesAndUniqueConstraintsArePrefixed(): void
    {
        $metadata                             = $this->metadata(HttpLogEntry::class);
        $metadata->table['uniqueConstraints'] = ['uniq_x' => ['columns' => ['id']], 0 => ['columns' => ['ip_address']]];

        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($metadata));

        $this->assertSame('app_nowo_http_log_entry', $metadata->getTableName());
        $this->assertSame(['app_idx_http_log_route_name', 'app_idx_http_log_created_at'], array_keys($metadata->table['indexes'] ?? []));
        $this->assertSame(['app_uniq_x', 0], array_keys($metadata->table['uniqueConstraints'] ?? []));
    }

    public function testEntitiesWithoutIndexesOnlyGetTheTablePrefix(): void
    {
        $metadata = new ClassMetadata(HttpLogEntry::class);
        $metadata->setPrimaryTable(['name' => 'nowo_http_log_entry']);

        (new TablePrefixListener('app_'))->loadClassMetadata($this->event($metadata));

        $this->assertSame('app_nowo_http_log_entry', $metadata->getTableName());
        $this->assertArrayNotHasKey('indexes', $metadata->table);
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable([
            'name'    => 'nowo_http_log_entry',
            'indexes' => [
                'idx_http_log_route_name' => ['columns' => ['ip_address', 'username']],
                'idx_http_log_created_at' => ['columns' => ['created_at']],
            ],
        ]);

        return $metadata;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function event(ClassMetadata $metadata): LoadClassMetadataEventArgs
    {
        return new LoadClassMetadataEventArgs($metadata, $this->createStub(EntityManagerInterface::class));
    }
}
