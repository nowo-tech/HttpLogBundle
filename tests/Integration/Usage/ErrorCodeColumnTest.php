<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Integration\Usage;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Model\HttpLogListRow;
use Nowo\HttpLogBundle\Tests\Support\UsageTestDatabase;
use Nowo\HttpLogBundle\Usage\UsageCapture;
use Nowo\HttpLogBundle\Usage\UsageEntryWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * The `error_code` column exists only while a provider configures it: every reader and writer follows the mapping, so a
 * host without the option keeps working on the schema it has.
 */
final class ErrorCodeColumnTest extends TestCase
{
    #[Test]
    public function withTheColumnTheListProjectionAndTheFilterUseIt(): void
    {
        $db     = new UsageTestDatabase(errorCode: true);
        $writer = new UsageEntryWriter($db);
        $writer->write(new UsageCapture('outbound', new DateTimeImmutable(), 'POST', '/token', provider: 'login', statusCode: 401, errorCode: 'invalid_client'));
        $writer->write(new UsageCapture('outbound', new DateTimeImmutable(), 'POST', '/token', provider: 'login', statusCode: 400, errorCode: 'invalid_grant'));
        $repository = $db->repository();

        self::assertTrue($repository->hasErrorCodeColumn());
        $all = $repository->findListRows([], 1, 10)['items'];
        self::assertEqualsCanonicalizing(['invalid_client', 'invalid_grant'], array_map(static fn (HttpLogListRow $row): ?string => $row->errorCode, $all));

        $filtered = $repository->findListRows(['errorCode' => 'invalid_grant'], 1, 10)['items'];
        self::assertCount(1, $filtered);
        self::assertSame(400, $filtered[0]->statusCode);
        self::assertSame(1, $repository->countFiltered(['errorCode' => 'invalid_client']));
        self::assertSame(2, $repository->countFiltered(['errorCode' => '']));
    }

    #[Test]
    public function withoutTheColumnTheListAndTheFilterIgnoreIt(): void
    {
        $db = new UsageTestDatabase();
        (new UsageEntryWriter($db))->write(new UsageCapture('outbound', new DateTimeImmutable(), 'POST', '/token', provider: 'login', statusCode: 401, errorCode: 'invalid_client'));
        $repository = $db->repository();

        self::assertFalse($repository->hasErrorCodeColumn());
        $rows = $repository->findListRows(['errorCode' => 'invalid_client'], 1, 10)['items'];
        self::assertCount(1, $rows);
        self::assertNull($rows[0]->errorCode);
        self::assertArrayNotHasKey('error_code', $db->rows()[0]);
    }
}
