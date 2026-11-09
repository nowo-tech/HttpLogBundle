<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Nowo\HttpLogBundle\Rollup\RollupTables;
use Nowo\HttpLogBundle\Rollup\UsageRollupStore;

use function array_values;
use function in_array;
use function sprintf;

/**
 * A query on the daily rollup (HL-1 §11.1). Immutable: each `with…()` returns a copy.
 *
 * ```php
 * $q = UsageQuery::forTenant($org->getUuid())->withChannels('api')->between($from, $to)->groupBy('day', 'credential_id');
 * ```
 */
final readonly class UsageQuery
{
    /** Columns a query can group by. */
    public const GROUP_KEYS = RollupTables::KEY_COLUMNS;

    /**
     * @param list<string> $channels
     * @param list<string> $providers
     * @param list<string> $credentialIds
     * @param list<string> $groupBy
     */
    private function __construct(
        public UsageScope $scope,
        public array $channels = [],
        public array $providers = [],
        public ?string $credentialKind = null,
        public array $credentialIds = [],
        public ?string $fromDay = null,
        public ?string $toDay = null,
        public array $groupBy = ['day'],
    ) {
    }

    /** @throws InvalidArgumentException on an empty or invalid tenant key */
    public static function forTenant(string $tenantKey): self
    {
        return new self(UsageScope::forTenant($tenantKey));
    }

    public static function platform(): self
    {
        return new self(UsageScope::platform());
    }

    public function withChannels(string ...$channels): self
    {
        return new self($this->scope, array_values($channels), $this->providers, $this->credentialKind, $this->credentialIds, $this->fromDay, $this->toDay, $this->groupBy);
    }

    public function withProviders(string ...$providers): self
    {
        return new self($this->scope, $this->channels, array_values($providers), $this->credentialKind, $this->credentialIds, $this->fromDay, $this->toDay, $this->groupBy);
    }

    /** One credential kind and (optionally) some of its ids; {@see UsageRollupStore::ERASED_CREDENTIAL_ID} names the erased ones. */
    public function withCredential(string $kind, string ...$ids): self
    {
        return new self($this->scope, $this->channels, $this->providers, $kind, array_values($ids), $this->fromDay, $this->toDay, $this->groupBy);
    }

    /** Inclusive UTC days (the time of day and the time zone of the arguments are ignored: their UTC date is used). */
    public function between(DateTimeInterface $from, DateTimeInterface $to): self
    {
        return new self($this->scope, $this->channels, $this->providers, $this->credentialKind, $this->credentialIds, $this->day($from), $this->day($to), $this->groupBy);
    }

    /**
     * Columns of the result rows (any subset of {@see GROUP_KEYS}); none = one total row.
     *
     * @throws InvalidArgumentException on an unknown column
     */
    public function groupBy(string ...$keys): self
    {
        foreach ($keys as $key) {
            if (!in_array($key, self::GROUP_KEYS, true)) {
                throw new InvalidArgumentException(sprintf('Unknown usage group key "%s".', $key));
            }
        }

        return new self($this->scope, $this->channels, $this->providers, $this->credentialKind, $this->credentialIds, $this->fromDay, $this->toDay, array_values($keys));
    }

    private function day(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    }
}
