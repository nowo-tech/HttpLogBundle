<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function array_diff;
use function array_keys;
use function array_values;
use function basename;
use function glob;
use function in_array;
use function is_array;
use function mb_strlen;
use function preg_match;
use function preg_replace;
use function sort;
use function sprintf;

/**
 * Guards against untranslated strings: in every non-en catalogue (all domains) a key must exist, and a value identical to
 * the English one (any length, ignoring values without letters once placeholders are removed) must be listed in
 * ALLOWED_IDENTICAL for that locale. A non-es value identical to the Spanish one (longer than 12 characters, not English)
 * must be listed in ALLOWED_SAME_AS_SPANISH. An allow-list entry that no longer applies is reported as stale.
 */
final class TranslationCatalogueTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../src/Resources/translations';

    /**
     * Keys that are correctly identical to English in a locale (brands, product and technical names, codes, loanwords
     * and cognates), per locale.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_IDENTICAL = [
        'de' => [
            'admin.column.id',
            'admin.column.status',
            'admin.field.external_message_id',
            'admin.field.name',
            'admin.field.route',
            'admin.field.url',
            'filter.path_placeholder',
            'filter.route_name_placeholder',
        ],
        'es' => [
            'admin.column.id',
            'admin.field.external_message_id',
            'admin.field.url',
            'filter.path_placeholder',
            'filter.route_name_placeholder',
        ],
        'fr' => [
            'admin.column.id',
            'admin.field.external_message_id',
            'admin.field.route',
            'admin.field.url',
            'filter.path_placeholder',
            'filter.route_name_placeholder',
        ],
        'it' => [
            'admin.column.id',
            'admin.field.external_message_id',
            'admin.field.url',
            'filter.path_placeholder',
            'filter.route_name_placeholder',
        ],
        'nl' => [
            'admin.column.id',
            'admin.column.status',
            'admin.field.external_message_id',
            'admin.field.project_key',
            'admin.field.route',
            'admin.field.url',
            'filter.path_placeholder',
            'filter.project_key',
            'filter.route_name_placeholder',
        ],
        'pt' => [
            'admin.column.id',
            'admin.field.external_message_id',
            'admin.field.url',
            'filter.path_placeholder',
            'filter.route_name_placeholder',
        ],
    ];

    /**
     * Keys whose non-es value legitimately equals the Spanish one (longer than 12 characters, different from English):
     * words and phrases that are genuinely the same in Spanish and the locale, per locale.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_SAME_AS_SPANISH = [
        'pt' => [
            'admin.action.delete',
            'admin.action.export_json',
            'admin.index.total',
            'admin.pagination.page',
            'filter.credential_kind',
            'filter.status_code',
        ],
    ];

    /** @return iterable<string, array{string, string}> */
    public static function catalogues(): iterable
    {
        $files = glob(self::DIR . '/*.yaml') ?: [];
        sort($files);

        foreach ($files as $file) {
            if (preg_match('/^(.+)\.([a-z]{2})\.yaml$/', basename($file), $m) === 1 && $m[2] !== 'en') {
                yield basename($file) => [$m[1], $m[2]];
            }
        }
    }

    #[DataProvider('catalogues')]
    public function testLocaleHasEveryEnglishKey(string $domain, string $locale): void
    {
        $missing = array_values(array_diff(array_keys($this->load($domain, 'en')), array_keys($this->load($domain, $locale))));

        self::assertSame([], $missing, sprintf('Keys of %s.en.yaml missing in %s.%s.yaml', $domain, $domain, $locale));
    }

    #[DataProvider('catalogues')]
    public function testNoUntranslatedEnglishValues(string $domain, string $locale): void
    {
        $english = $this->load($domain, 'en');
        $target  = $this->load($domain, $locale);
        $allowed = self::ALLOWED_IDENTICAL[$locale] ?? [];
        $gaps    = [];

        foreach ($english as $key => $value) {
            if (!isset($target[$key]) || $target[$key] !== $value || in_array($key, $allowed, true)) {
                continue;
            }

            if (preg_match('/[^\W\d_]/u', (string) preg_replace('/%[^%\s]+%|\{[^}]+\}/', '', $value)) === 1) {
                $gaps[] = $key;
            }
        }

        self::assertSame([], $gaps, sprintf('Values identical to English in %s.%s.yaml (translate or allow-list)', $domain, $locale));
    }

    /** @return iterable<string, array{string, string}> */
    public static function nonSpanishCatalogues(): iterable
    {
        foreach (self::catalogues() as $name => [$domain, $locale]) {
            if ($locale !== 'es') {
                yield $name => [$domain, $locale];
            }
        }
    }

    #[DataProvider('nonSpanishCatalogues')]
    public function testNoSpanishLeftInOtherLocales(string $domain, string $locale): void
    {
        $english = $this->load($domain, 'en');
        $spanish = $this->load($domain, 'es');
        $target  = $this->load($domain, $locale);
        $allowed = self::ALLOWED_SAME_AS_SPANISH[$locale] ?? [];
        $leaks   = [];

        foreach ($spanish as $key => $value) {
            if (!isset($target[$key]) || $target[$key] !== $value || $value === ($english[$key] ?? null) || mb_strlen($value) <= 12 || in_array($key, $allowed, true)) {
                continue;
            }

            $leaks[] = $key;
        }

        self::assertSame([], $leaks, sprintf('Values identical to Spanish in %s.%s.yaml (translate or allow-list)', $domain, $locale));
    }

    public function testAllowListHasNoStaleEntries(): void
    {
        $english = [];
        $locales = [];

        foreach (self::catalogues() as [$domain, $locale]) {
            $english += $this->load($domain, 'en');
            $locales[$locale] = ($locales[$locale] ?? []) + $this->load($domain, $locale);
        }

        $stale = [];

        foreach (self::ALLOWED_IDENTICAL as $locale => $keys) {
            foreach ($keys as $key) {
                if (!isset($english[$key]) || ($locales[$locale][$key] ?? null) !== $english[$key]) {
                    $stale[] = $locale . ':' . $key;
                }
            }
        }

        self::assertSame([], $stale, 'Allow-list entries that no longer exist or are no longer identical to English');
    }

    public function testSpanishAllowListHasNoStaleEntries(): void
    {
        $english = [];
        $spanish = [];
        $locales = [];

        foreach (self::catalogues() as [$domain, $locale]) {
            $english += $this->load($domain, 'en');
            $spanish += $this->load($domain, 'es');
            $locales[$locale] = ($locales[$locale] ?? []) + $this->load($domain, $locale);
        }

        $stale = [];

        foreach (self::ALLOWED_SAME_AS_SPANISH as $locale => $keys) {
            foreach ($keys as $key) {
                $value = $spanish[$key] ?? null;

                if ($value === null || ($locales[$locale][$key] ?? null) !== $value || $value === ($english[$key] ?? null) || mb_strlen($value) <= 12) {
                    $stale[] = $locale . ':' . $key;
                }
            }
        }

        self::assertSame([], $stale, 'Spanish allow-list entries that no longer exist or no longer equal the Spanish value');
    }

    /** @return array<string, string> */
    private function load(string $domain, string $locale): array
    {
        $data = Yaml::parseFile(sprintf('%s/%s.%s.yaml', self::DIR, $domain, $locale));

        return $this->flatten(is_array($data) ? $data : []);
    }

    /**
     * @param array<mixed> $tree
     *
     * @return array<string, string>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = (string) $value;
            }
        }

        return $out;
    }
}
