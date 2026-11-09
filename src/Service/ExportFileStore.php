<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Service;

use DateTimeImmutable;
use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Security\HttpLogChannelAccessCheckerInterface;
use RuntimeException;

use function array_diff;
use function array_filter;
use function array_slice;
use function array_unique;
use function array_values;
use function bin2hex;
use function chmod;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function filesize;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rename;
use function rtrim;
use function scandir;
use function sprintf;
use function str_ends_with;
use function strlen;
use function substr;
use function time;
use function unlink;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * Private storage of background (Messenger) exports.
 *
 * Exports contain request logs (paths, headers, bodies, user identifiers): they are written to a private directory
 * (0700, files 0600) under a random name, renamed from `<name>.part` when complete, offered for download only to users
 * allowed to export, and deleted after `export.ttl_hours`.
 *
 * The store is shared by every user allowed to export (as in 1.14). When the access checker restricts channels
 * ({@see HttpLogChannelAccessCheckerInterface}), the channel set an export was built
 * for is kept beside it (`<name>.channels`) and the export is listed / served only to viewers allowed every one of
 * those channels; an export without that file was built unrestricted and is hidden from restricted viewers.
 */
final readonly class ExportFileStore
{
    private const NAME_PATTERN = '/^[a-f0-9]{32}\.(csv|json)$/';

    /** Sidecar holding the channel set of a restricted export. */
    public const CHANNELS_SUFFIX = '.channels';

    public function __construct(
        private string $directory,
        private int $ttlSeconds = 86400,
    ) {
    }

    /**
     * Reserves a new export name (`<32 hex>.<format>`); the directory is created on first use.
     *
     * @param list<string>|null $channels channels the export is restricted to (null = unrestricted)
     */
    public function reserve(ExportFormat $format, ?array $channels = null): string
    {
        $this->ensureDirectory();

        $name = bin2hex(random_bytes(16)) . '.' . $format->value;
        if ($channels !== null) {
            // Fails safe: without its sidecar the export counts as unrestricted, so only unrestricted viewers (who may
            // see every channel anyway) are offered it.
            $sidecar = $this->path($name) . self::CHANNELS_SUFFIX;
            if (@file_put_contents($sidecar, json_encode(array_values(array_unique($channels)), JSON_THROW_ON_ERROR)) !== false) {
                chmod($sidecar, 0o600);
            }
        }

        return $name;
    }

    /**
     * Channels an export was restricted to when it was reserved, or null when it was built unrestricted.
     *
     * @return list<string>|null
     */
    public function channels(string $name): ?array
    {
        $sidecar = $this->path($this->assertName($name)) . self::CHANNELS_SUFFIX;
        if (!is_file($sidecar)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($sidecar), true);

        // an unreadable sidecar counts as unrestricted: only unrestricted viewers see the export
        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : null;
    }

    /**
     * May a viewer with these allowed channels (null = unrestricted) see this export?
     *
     * @param list<string>|null $viewerChannels
     */
    public function isVisibleTo(string $name, ?array $viewerChannels): bool
    {
        if ($viewerChannels === null) {
            return true;
        }
        $channels = $this->channels($name);

        return $channels !== null && array_diff($channels, $viewerChannels) === [];
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

    /**
     * Absolute path of a finished, not expired export the viewer may see, or null (unknown / invalid name, still
     * running, expired, built for channels the viewer is not allowed).
     *
     * @param list<string>|null $viewerChannels channels the viewer may see (null = unrestricted)
     */
    public function readyPath(string $name, ?array $viewerChannels = null): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }
        $path = $this->path($name);
        if (!is_file($path) || $this->isExpired($path) || !$this->isVisibleTo($name, $viewerChannels)) {
            return null;
        }

        return $path;
    }

    /**
     * Finished, not expired exports the viewer may see, newest first.
     *
     * @param list<string>|null $viewerChannels channels the viewer may see (null = unrestricted)
     *
     * @return list<array{name: string, format: ExportFormat, size: int, createdAt: DateTimeImmutable}>
     */
    public function ready(int $limit = 3, ?array $viewerChannels = null): array
    {
        $out = [];
        foreach ($this->entries() as $entry) {
            if (preg_match(self::NAME_PATTERN, $entry, $m) !== 1) {
                continue;
            }
            $path = $this->path($entry);
            if ($this->isExpired($path) || !$this->isVisibleTo($entry, $viewerChannels)) {
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
            $name = match (true) {
                str_ends_with($entry, '.part')               => substr($entry, 0, -5),
                str_ends_with($entry, self::CHANNELS_SUFFIX) => substr($entry, 0, -strlen(self::CHANNELS_SUFFIX)),
                default                                      => $entry,
            };
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
