<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Inbound;

/**
 * What a {@see HttpLogRequestContextResolverInterface} says about one request of a named channel (HL-1 §6.3).
 *
 * Every value is an opaque key, `^[A-Za-z0-9._:@-]+$`: the credential kind (at most 32 characters; e.g.
 * `personal_token`, `device_token`, `dsn_key`, `mcp_client`, `signature`) and the credential's **database id** (row id
 * or UUID, at most 64), never the token, the secret or a prefix of either; the tenant key (organization UUID) and the
 * project key (project UUID, per-call rows only: the rollup is keyed on the tenant). The bundle drops any value that
 * fails the charset or length rule, or that equals or contains a credential the request carried (see
 * {@see HttpLogRequestContextResolution}).
 */
final readonly class HttpLogRequestContext
{
    public function __construct(
        public ?string $credentialKind = null,
        public ?string $credentialId = null,
        public ?string $tenantKey = null,
        public ?string $projectKey = null,
    ) {
    }
}
