<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use DateTimeImmutable;

/**
 * "Last used" of one credential (HL-1 §11.1, Account → API tokens): from the rollup, so it survives the 30-day row
 * retention. Calls and errors of the last 30 UTC days, today included.
 */
final readonly class CredentialUsage
{
    public function __construct(
        public string $credentialId,
        public DateTimeImmutable $lastSeenAt,
        public int $calls30d,
        public int $errors30d,
    ) {
    }
}
