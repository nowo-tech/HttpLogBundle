<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Rollup;

use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Deprecations\Deprecation;
use Nowo\HttpLogBundle\Rollup\RollupTables;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function method_exists;

/**
 * 1.23.1: the rollup tables get their primary key through a primary key constraint where doctrine/dbal has one (4.3+),
 * so building them no longer raises the `Table::setPrimaryKey()` deprecation (doctrine/dbal#6867); the key is the same.
 */
final class RollupTablesPrimaryKeyTest extends TestCase
{
    #[Test]
    public function addingTheTablesRaisesNoSetPrimaryKeyDeprecation(): void
    {
        $link = 'https://github.com/doctrine/dbal/pull/6867';
        Deprecation::enableTrackingDeprecations();
        try {
            $before = Deprecation::getTriggeredDeprecations()[$link] ?? 0;
            $schema = new Schema();
            (new RollupTables())->addTo($schema, 'utf8mb4_0900_bin');

            self::assertSame($before, Deprecation::getTriggeredDeprecations()[$link] ?? 0);
        } finally {
            Deprecation::disable();
        }
        self::assertSame(['id'], $this->primaryKey($schema->getTable('nowo_http_log_daily')));
        self::assertSame(['event_id'], $this->primaryKey($schema->getTable('nowo_http_log_event_seen')));
    }

    /**
     * @return list<string>
     */
    private function primaryKey(Table $table): array
    {
        // @phpstan-ignore function.alreadyNarrowedType (getPrimaryKeyConstraint() exists only from doctrine/dbal 4.3)
        if (method_exists($table, 'getPrimaryKeyConstraint')) {
            $constraint = $table->getPrimaryKeyConstraint();
            self::assertNotNull($constraint);

            return array_map(static fn (UnqualifiedName $name): string => $name->toString(), $constraint->getColumnNames());
        }

        return $table->getPrimaryKey()?->getColumns() ?? []; // @codeCoverageIgnore
    }
}
