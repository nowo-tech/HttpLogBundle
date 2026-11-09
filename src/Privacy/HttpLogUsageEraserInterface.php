<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use InvalidArgumentException;

/**
 * Erasure of usage data by credential and by tenant (HL-1 §10), beside {@see HttpLogPersonalDataEraserInterface}
 * (unchanged). Matching is exact and byte-for-byte whatever the collation; empty values and values with a NUL byte
 * are rejected. Deletes run in batches of `retention.purge_batch_size`.
 */
interface HttpLogUsageEraserInterface
{
    /** Credential id that an erased credential's rollup buckets are folded into (`UsageRollupStore::ERASED_CREDENTIAL_ID`). */
    public const ERASED_CREDENTIAL_ID = '~erased';

    /**
     * Per-call rows recorded for a credential.
     *
     * @throws InvalidArgumentException when the kind or id is empty or contains a NUL byte
     */
    public function countByCredential(string $kind, string $id): int;

    /**
     * Deletes the credential's per-call rows, and folds its rollup buckets into the bucket of the same day, channel,
     * provider and tenant with credential id {@see ERASED_CREDENTIAL_ID} (the totals stay), then deletes them.
     *
     * @throws InvalidArgumentException when the kind or id is empty or contains a NUL byte
     *
     * @return int per-call rows deleted
     */
    public function eraseCredential(string $kind, string $id): int;

    /**
     * Deletes every per-call row and every rollup bucket of a (deleted) tenant.
     *
     * @throws InvalidArgumentException when the key is empty or contains a NUL byte
     *
     * @return int rows plus buckets deleted
     */
    public function eraseTenant(string $tenantKey): int;
}
