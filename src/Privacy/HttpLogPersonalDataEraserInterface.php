<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use InvalidArgumentException;

/**
 * Public API for personal-data erasure (GDPR art. 17) of HTTP log entries.
 *
 * An entry stores two identifiers of a person: the authenticated `user_identifier` (`UserInterface::getUserIdentifier()`)
 * and the `client_ip`. Matching is exact and byte-for-byte, whatever the database collation (a case- or
 * accent-insensitive MySQL collation only pre-filters; `ana` never erases `ANA`, `Ana` or `ána`). IP addresses are
 * compared as logged, without normalisation (pass the form `Request::getClientIp()` returned). Values that are empty or
 * contain a NUL byte are rejected. Deletes run in batches of `retention.purge_batch_size` rows, so a large log
 * is never locked in one statement. Hosts use this service instead of SQL on the bundle table, whose name depends on
 * `doctrine.table_name_prefix` / `doctrine.table_prefix`.
 */
interface HttpLogPersonalDataEraserInterface
{
    /**
     * Counts the entries recorded for an authenticated user.
     *
     * @param string $userIdentifier Value of `UserInterface::getUserIdentifier()` when the request was logged
     *
     * @throws InvalidArgumentException when the identifier is empty or contains a NUL byte
     */
    public function countByUserIdentifier(string $userIdentifier): int;

    /**
     * Deletes every entry recorded for an authenticated user.
     *
     * @param string $userIdentifier Value of `UserInterface::getUserIdentifier()` when the request was logged
     *
     * @throws InvalidArgumentException when the identifier is empty or contains a NUL byte
     *
     * @return int Entries deleted
     */
    public function deleteByUserIdentifier(string $userIdentifier): int;

    /**
     * Counts the entries recorded from a client IP address.
     *
     * @param string $clientIp IPv4 or IPv6 address as logged (after any trusted-proxy resolution)
     *
     * @throws InvalidArgumentException when the address is empty or contains a NUL byte
     */
    public function countByClientIp(string $clientIp): int;

    /**
     * Deletes every entry recorded from a client IP address.
     *
     * @param string $clientIp IPv4 or IPv6 address as logged (after any trusted-proxy resolution)
     *
     * @throws InvalidArgumentException when the address is empty or contains a NUL byte
     *
     * @return int Entries deleted
     */
    public function deleteByClientIp(string $clientIp): int;
}
