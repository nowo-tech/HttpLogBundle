<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Privacy;

use InvalidArgumentException;

/**
 * Personal-data export (GDPR art. 15 / 20) of one subject's HTTP log entries, and the client IP windows of a user that
 * other bundles need to erase their own IP-keyed rows.
 *
 * Matching is exact and byte-for-byte whatever the database collation (the SQL `=` only pre-filters, every candidate is
 * compared with `===`), like {@see HttpLogPersonalDataEraserInterface}.
 */
interface HttpLogPersonalDataExporterInterface
{
    /**
     * JSON-serialisable export: request metadata only (time, method, scheme, host, path, route, status, duration, client
     * IP, channel), never query parameters, headers, bodies or credential / tenant columns. At most
     * `personal_data.export_limit` entries, newest first; `total` counts every matching entry and `truncated` says
     * whether entries were left out. `client_ip_windows` covers every matching entry.
     *
     * @return array{subject: array{type: string, value: string}, total: int, limit: int, truncated: bool, client_ip_windows: list<array{client_ip: string, first_seen_at: string, last_seen_at: string}>, entries: list<array{created_at: string, method: string, scheme: string|null, host: string|null, path: string, route_name: string|null, status_code: int|null, duration_ms: float|null, client_ip: string|null, channel: string|null}>}
     */
    public function export(HttpLogPersonalDataSubject $subject): array;

    /**
     * Every distinct client IP address logged for a user, with the first and last time it was seen for that user,
     * ordered by first sighting (then address). Entries without an IP are skipped.
     *
     * @param string $userIdentifier Value of `UserInterface::getUserIdentifier()` when the requests were logged
     *
     * @throws InvalidArgumentException when the identifier is empty, contains a NUL byte or is longer than 255 characters
     *
     * @return list<HttpLogClientIpWindow>
     */
    public function clientIpWindows(string $userIdentifier): array;
}
