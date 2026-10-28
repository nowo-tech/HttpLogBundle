<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Enum\ExportFormat;
use RuntimeException;

use function array_filter;
use function array_slice;
use function array_values;
use function bin2hex;
use function chmod;
use function filemtime;
use function filesize;
use function is_dir;
use function is_file;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rename;
use function rtrim;
use function scandir;
use function sprintf;
use function str_ends_with;
use function substr;
use function time;
use function unlink;
use function usort;

/**
 * Private storage of background (Messenger) exports.
 *
 * Exports contain request logs (paths, headers, bodies, user identifiers): they are written to a private directory
 * (0700, files 0600) under a random name, renamed from `<name>.part` when complete, offered for download only to users
 * allowed to export, and deleted after `export.ttl_hours`.
 */
final readonly class ExportFileStore
{
    private const NAME_PATTERN = '/^[a-f0-9]{32}\.(csv|json)$/';

    public function __construct(
        private string $directory,
        private int $ttlSeconds = 86400,
    ) {
    }

    /** Reserves a new export name (`<32 hex>.<format>`); the directory is created on first use. */
    public function reserve(ExportFormat $format): string
    {
        $this->ensureDirectory();

        return bin2hex(random_bytes(16)) . '.' . $format->value;
    }

    /** Path the export job writes to until it is complete. */
    public function partialPath(string $name): string
    {
        return $this->path($this->assertName($name)) . '.part';
    }

    /** Publishes a finished export (`<name>.part` → `<name>`, mode 0600). */
    public function complete(string $name): void
    {
        $final = $this->path($this->assertName($name));
        $part  = $final . '.part';
        if (!is_file($part) || !rename($part, $final)) {
            throw new RuntimeException(sprintf('Unable to complete HTTP log export "%s".', $name));
        }
        chmod($final, 0o600);
    }

    /** Absolute path of a finished, not expired export, or null (unknown / invalid name, still running, expired). */
    public function readyPath(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }
        $path = $this->path($name);
        if (!is_file($path) || $this->isExpired($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Finished, not expired exports, newest first.
     *
     * @return list<array{name: string, format: ExportFormat, size: int, createdAt: DateTimeImmutable}>
     */
    public function ready(int $limit = 3): array
    {
        $out = [];
        foreach ($this->entries() as $entry) {
            if (preg_match(self::NAME_PATTERN, $entry, $m) !== 1) {
                continue;
            }
            $path = $this->path($entry);
            if ($this->isExpired($path)) {
                continue;
            }
            $out[] = [
                'name'      => $entry,
                'format'    => ExportFormat::from($m[1]),
                'size'      => (int) filesize($path),
                'createdAt' => new DateTimeImmutable('@' . (int) filemtime($path)),
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['createdAt'] <=> $a['createdAt']);

        return array_slice($out, 0, $limit);
    }

    /** Deletes expired exports (finished and abandoned `.part` files); returns the number of deleted files. */
    public function purgeExpired(): int
    {
        $deleted = 0;
        foreach ($this->entries() as $entry) {
            $name = str_ends_with($entry, '.part') ? substr($entry, 0, -5) : $entry;
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                continue;
            }
            $path = $this->path($entry);
            if ($this->isExpired($path) && unlink($path)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    /** @return list<string> */
    private function entries(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }
        $entries = scandir($this->directory);

        return $entries === false ? [] : array_values(array_filter($entries, static fn (string $e): bool => $e !== '.' && $e !== '..'));
    }

    private function isExpired(string $path): bool
    {
        $mtime = filemtime($path);

        return $mtime === false || $mtime + $this->ttlSeconds < time();
    }

    private function ensureDirectory(): void
    {
        // @: a failure is reported by the exception below (race with a concurrent job is tolerated by the re-check).
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Unable to create the HTTP log export directory "%s".', $this->directory));
        }
    }

    private function assertName(string $name): string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new RuntimeException(sprintf('Invalid HTTP log export name "%s".', $name));
        }

        return $name;
    }

    private function path(string $entry): string
    {
        return rtrim($this->directory, '/') . '/' . $entry;
    }
}
