<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use InvalidArgumentException;

/**
 * Tenant scope of a usage query (HL-1 §11.1): one tenant, or the whole platform. There is no default: a query is
 * always built with {@see forTenant()} or {@see platform()}, and a tenant query never returns another tenant's row
 * or bucket (nor one without a tenant).
 */
final readonly class UsageScope
{
    private function __construct(public ?string $tenantKey)
    {
    }

    /**
     * @throws InvalidArgumentException when the key is empty or not an opaque key (`^[A-Za-z0-9._:@-]{1,64}$`)
     */
    public static function forTenant(string $tenantKey): self
    {
        if (UsageFieldGuard::token($tenantKey) === null) {
            throw new InvalidArgumentException('A usage query needs a valid, non-empty tenant key (or UsageQuery::platform()).');
        }

        return new self($tenantKey);
    }

    /** Every tenant, and the calls without one (platform pages). */
    public static function platform(): self
    {
        return new self(null);
    }

    public function isPlatform(): bool
    {
        return $this->tenantKey === null;
    }
}
