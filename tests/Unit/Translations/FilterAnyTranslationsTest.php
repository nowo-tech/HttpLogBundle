<?php

declare(strict_types=1);

namespace Nowo\HttpLogBundle\Tests\Unit\Translations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function is_array;

/** 1.25: the "Any ..." filter options and the client IP placeholder are really translated in every shipped locale. */
final class FilterAnyTranslationsTest extends TestCase
{
    private const KEYS = ['filter.any', 'filter.any_method', 'filter.any_body_type', 'filter.any_channel'];

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        foreach (['en', 'es', 'fr', 'de', 'it', 'pt', 'nl'] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[Test]
    #[DataProvider('locales')]
    public function everyAnyKeyIsTranslated(string $locale): void
    {
        $catalogue = $this->load($locale);
        $english   = $this->load('en');

        foreach ([...self::KEYS, 'filter.client_ip_placeholder'] as $key) {
            self::assertArrayHasKey($key, $catalogue, $key . ' (' . $locale . ')');
            self::assertNotSame('', $catalogue[$key]);
            self::assertNotSame($key, $catalogue[$key]);
        }
        if ($locale !== 'en') {
            foreach (self::KEYS as $key) {
                self::assertNotSame($english[$key], $catalogue[$key], $key . ' still English in ' . $locale);
            }
        }
    }

    /** @return array<string, string> */
    private function load(string $locale): array
    {
        $data = Yaml::parseFile(dirname(__DIR__, 3) . '/src/Resources/translations/NowoHttpLogBundle.' . $locale . '.yaml');
        self::assertTrue(is_array($data));

        return $data;
    }
}
