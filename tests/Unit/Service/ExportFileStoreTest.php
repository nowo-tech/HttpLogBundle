<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Service;

use Nowo\HttpLogBundle\Enum\ExportFormat;
use Nowo\HttpLogBundle\Service\ExportFileStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function bin2hex;
use function chmod;
use function file_put_contents;
use function fileperms;
use function glob;
use function is_dir;
use function random_bytes;
use function rmdir;
use function sort;
use function str_repeat;
use function sys_get_temp_dir;
use function time;
use function touch;
use function unlink;

final class ExportFileStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/http-log-store-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            chmod($this->dir, 0o700);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    #[Test]
    public function restrictedExportsAreOfferedOnlyToViewersAllowedAllTheirChannels(): void
    {
        $store      = new ExportFileStore($this->dir);
        $open       = $store->reserve(ExportFormat::Csv);
        $restricted = $store->reserve(ExportFormat::Json, ['web', 'outbound', 'web']);
        $broken     = $store->reserve(ExportFormat::Csv, ['mailer']);
        foreach ([$open, $restricted, $broken] as $name) {
            file_put_contents($store->partialPath($name), 'x');
            $store->complete($name);
        }
        file_put_contents($this->dir . '/' . $broken . ExportFileStore::CHANNELS_SUFFIX, 'not json');

        self::assertNull($store->channels($open));
        self::assertSame(['web', 'outbound'], $store->channels($restricted));
        self::assertNull($store->channels($broken), 'unreadable: counts as unrestricted');
        self::assertSame(0o600, fileperms($this->dir . '/' . $restricted . ExportFileStore::CHANNELS_SUFFIX) & 0o777);

        self::assertCount(3, $store->ready(10));
        self::assertSame([$restricted], $this->names($store->ready(10, ['web', 'outbound', 'mailer'])), 'unrestricted (and unreadable) exports hidden');
        self::assertSame([], $store->ready(10, ['web']));
        self::assertNull($store->readyPath($open, ['web', 'outbound', 'mailer']));
        self::assertNotNull($store->readyPath($restricted, ['outbound', 'web']));
        self::assertNull($store->readyPath($restricted, ['web']));
        self::assertNotNull($store->readyPath($open));
    }

    #[Test]
    public function expiredSidecarsArePurgedWithTheirExport(): void
    {
        $store = new ExportFileStore($this->dir, 60);
        $name  = $store->reserve(ExportFormat::Csv, ['web']);
        file_put_contents($store->partialPath($name), 'x');
        $store->complete($name);
        foreach ([$name, $name . ExportFileStore::CHANNELS_SUFFIX] as $file) {
            touch($this->dir . '/' . $file, time() - 3600);
        }

        self::assertSame(2, $store->purgeExpired());
        self::assertFileDoesNotExist($this->dir . '/' . $name . ExportFileStore::CHANNELS_SUFFIX);
    }

    /**
     * @param list<array{name: string}> $ready
     *
     * @return list<string>
     */
    private function names(array $ready): array
    {
        $names = array_column($ready, 'name');
        sort($names);

        return $names;
    }

    #[Test]
    public function reserveCreatesAPrivateDirectoryAndRandomNames(): void
    {
        $store = new ExportFileStore($this->dir . '/');

        $a = $store->reserve(ExportFormat::Csv);
        $b = $store->reserve(ExportFormat::Json);

        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.csv$/', $a);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}\.json$/', $b);
        self::assertSame(0o700, fileperms($this->dir) & 0o777);
        self::assertSame($this->dir . '/' . $a . '.part', $store->partialPath($a));
    }

    #[Test]
    public function completedExportsAreOwnerOnlyAndListedNewestFirst(): void
    {
        $store = new ExportFileStore($this->dir);
        $old   = $store->reserve(ExportFormat::Csv);
        file_put_contents($store->partialPath($old), 'old');
        $store->complete($old);
        touch($this->dir . '/' . $old, time() - 60);
        $new = $store->reserve(ExportFormat::Json);
        file_put_contents($store->partialPath($new), '[]');
        $store->complete($new);
        $running = $store->reserve(ExportFormat::Csv);
        file_put_contents($store->partialPath($running), 'partial');
        file_put_contents($this->dir . '/notes.txt', 'ignored');

        self::assertSame(0o600, fileperms($this->dir . '/' . $new) & 0o777);
        self::assertSame($this->dir . '/' . $new, $store->readyPath($new));
        self::assertNull($store->readyPath($running), 'still running');
        self::assertNull($store->readyPath('../etc/passwd'));
        self::assertNull($store->readyPath(str_repeat('c', 32) . '.csv'));

        $ready = $store->ready();
        self::assertSame([$new, $old], array_column($ready, 'name'));
        self::assertSame(ExportFormat::Json, $ready[0]['format']);
        self::assertSame(2, $ready[0]['size']);
        self::assertCount(1, $store->ready(1));
    }

    #[Test]
    public function purgeExpiredDeletesOldFinishedAndAbandonedFiles(): void
    {
        $store = new ExportFileStore($this->dir, 3600);
        $fresh = $store->reserve(ExportFormat::Csv);
        file_put_contents($store->partialPath($fresh), 'x');
        $store->complete($fresh);
        $oldDone = $this->dir . '/' . str_repeat('a', 32) . '.csv';
        $oldPart = $this->dir . '/' . str_repeat('b', 32) . '.json.part';
        $foreign = $this->dir . '/keep.txt';
        foreach ([$oldDone, $oldPart, $foreign] as $file) {
            file_put_contents($file, 'x');
            touch($file, time() - 7200);
        }

        self::assertNull($store->readyPath(str_repeat('a', 32) . '.csv'), 'expired');
        self::assertSame([$fresh], array_column($store->ready(), 'name'));
        self::assertSame(2, $store->purgeExpired());
        self::assertFileDoesNotExist($oldDone);
        self::assertFileDoesNotExist($oldPart);
        self::assertFileExists($foreign);
        self::assertFileExists($this->dir . '/' . $fresh);
    }

    #[Test]
    public function missingDirectoryHasNoExports(): void
    {
        $store = new ExportFileStore($this->dir);

        self::assertSame([], $store->ready());
        self::assertSame(0, $store->purgeExpired());
    }

    #[Test]
    public function completeFailsWithoutAPartialFile(): void
    {
        $store = new ExportFileStore($this->dir);
        $name  = $store->reserve(ExportFormat::Csv);

        $this->expectException(RuntimeException::class);
        $store->complete($name);
    }

    #[Test]
    public function invalidNamesAreRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new ExportFileStore($this->dir))->partialPath('../../x.csv');
    }

    #[Test]
    public function reserveFailsWhenTheDirectoryCannotBeCreated(): void
    {
        file_put_contents($this->dir, 'not a directory');
        try {
            $this->expectException(RuntimeException::class);
            (new ExportFileStore($this->dir . '/sub'))->reserve(ExportFormat::Csv);
        } finally {
            unlink($this->dir);
        }
    }
}
