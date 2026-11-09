<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Usage;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

use function array_values;
use function sprintf;

/**
 * A query on the per-call rows of the usage channels (HL-1 §11.1): named inbound channels, outbound, mailer; never
 * web rows. Immutable.
 */
final readonly class CallQuery
{
    /**
     * @param list<string> $channels
     * @param list<string> $providers
     * @param list<string> $credentialIds
     */
    private function __construct(
        public UsageScope $scope,
        public array $channels = [],
        public array $providers = [],
        public ?string $credentialKind = null,
        public array $credentialIds = [],
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?int $statusClass = null,
        public ?string $errorKind = null,
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
        return new self($this->scope, array_values($channels), $this->providers, $this->credentialKind, $this->credentialIds, $this->from, $this->to, $this->statusClass, $this->errorKind);
    }

    public function withProviders(string ...$providers): self
    {
        return new self($this->scope, $this->channels, array_values($providers), $this->credentialKind, $this->credentialIds, $this->from, $this->to, $this->statusClass, $this->errorKind);
    }

    public function withCredential(string $kind, string ...$ids): self
    {
        return new self($this->scope, $this->channels, $this->providers, $kind, array_values($ids), $this->from, $this->to, $this->statusClass, $this->errorKind);
    }

    /** Rows created at or after `$from` and before or at `$to` (stored wall-clock time, as the admin filters). */
    public function between(?DateTimeInterface $from, ?DateTimeInterface $to): self
    {
        return new self($this->scope, $this->channels, $this->providers, $this->credentialKind, $this->credentialIds, $from instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($from) : null, $to instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($to) : null, $this->statusClass, $this->errorKind);
    }

    /**
     * @param int $class 1..5 (2 = 2xx…)
     *
     * @throws InvalidArgumentException outside 1..5
     */
    public function withStatusClass(int $class): self
    {
        if ($class < 1 || $class > 5) {
            throw new InvalidArgumentException(sprintf('Status class must be 1..5, got %d.', $class));
        }

        return new self($this->scope, $this->channels, $this->providers, $this->credentialKind, $this->credentialIds, $this->from, $this->to, $class, $this->errorKind);
    }

    public function withErrorKind(string $errorKind): self
    {
        return new self($this->scope, $this->channels, $this->providers, $this->credentialKind, $this->credentialIds, $this->from, $this->to, $this->statusClass, $errorKind);
    }
}
