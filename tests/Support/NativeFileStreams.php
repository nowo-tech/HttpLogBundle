<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Support;

use DG\BypassFinals;
use DG\BypassFinals\MutatingWrapper;

use function fclose;
use function fopen;
use function stream_get_meta_data;
use function stream_wrapper_restore;

/**
 * Worker-mode memory tests measure the bundle, not the test harness.
 *
 * DG\BypassFinals (tests/bootstrap.php) swaps its `file://` stream wrapper out and back in around every filesystem
 * call, and each `stream_wrapper_register()` leaves a "stream factory" resource that PHP frees only at shutdown. A
 * kept-alive kernel touches files on every request (the config cache freshness checks and the route template map in
 * debug), so under the test bootstrap every request adds resources that production never has, and once the process
 * holds 2^17 of them the resource list doubles (about 5 MB in one request). The test then fails or passes depending on
 * how many file operations the earlier tests made, not on the bundle.
 *
 * {@see begin()} puts PHP's native wrapper back for the measured requests and {@see end()} enables BypassFinals again.
 * Call begin() after a warm-up request of each kind, so every class the loop needs is already loaded through
 * BypassFinals and stays mockable by the tests that run later in the same process.
 */
final class NativeFileStreams
{
    /** @return bool whether BypassFinals was active and is now off (pass it to {@see end()}) */
    public static function begin(): bool
    {
        if (!self::bypassFinalsActive()) {
            return false;
        }

        stream_wrapper_restore('file');

        return true;
    }

    public static function end(bool $begun): void
    {
        if ($begun) {
            BypassFinals::enable();
        }
    }

    private static function bypassFinalsActive(): bool
    {
        $handle = fopen(__FILE__, 'r');
        if ($handle === false) {
            return false;
        }

        try {
            return (stream_get_meta_data($handle)['wrapper_data'] ?? null) instanceof MutatingWrapper;
        } finally {
            fclose($handle);
        }
    }
}
