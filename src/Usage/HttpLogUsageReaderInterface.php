<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use InvalidArgumentException;
use LogicException;

/**
 * Read API for host usage pages (HL-1 §11.1). Every query carries an explicit tenant scope
 * ({@see UsageQuery::forTenant()} / {@see UsageQuery::platform()}, same for {@see CallQuery}): a tenant query never
 * returns another tenant's bucket or row. Authorisation stays with the host. Days are UTC.
 */
interface HttpLogUsageReaderInterface
{
    /**
     * Sums of the daily buckets, grouped by the query's keys, in one query.
     *
     * @throws LogicException when the rollup is not enabled
     *
     * @return list<DailyUsage>
     */
    public function daily(UsageQuery $query): array;

    /**
     * Same rows as {@see daily()}, streamed (CSV export by the host).
     *
     * @throws LogicException when the rollup is not enabled
     *
     * @return iterable<DailyUsage>
     */
    public function iterateDaily(UsageQuery $query): iterable;

    /**
     * Per-call rows of the usage channels (never web rows), newest first, keyset paginated on the row id.
     *
     * @param string|null $cursor the `nextCursor` of the previous page
     * @param int $limit 1..200
     *
     * @throws InvalidArgumentException on an invalid cursor or limit, or when `web` is asked for
     * @throws LogicException when no usage channel is enabled
     */
    public function calls(CallQuery $query, ?string $cursor = null, int $limit = 50): CallPage;

    /**
     * Last use and 30-day counts of credentials of one kind, within the query's scope (its channels and providers
     * apply; its days and grouping do not). Credentials without a bucket are absent from the result.
     *
     * @param list<string> $ids
     *
     * @throws LogicException when the rollup is not enabled
     *
     * @return array<string, CredentialUsage> credential id => usage
     */
    public function lastUsed(UsageQuery $scope, string $kind, array $ids): array;
}
