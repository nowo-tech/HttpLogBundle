#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * REQ-MAKE-004 — validate translation key parity against en catalogue.
 *
 * Usage: php .scripts/validate-translations.php [translations-dir]
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

$translationsDir = $argv[1] ?? __DIR__ . '/../src/Resources/translations';
$domainPrefix = 'NowoHttpLogBundle';
$referenceLocale = 'en';

if (!is_dir($translationsDir)) {
    fwrite(STDERR, "Translations directory not found: {$translationsDir}\n");
    exit(1);
}

$referenceFile = sprintf('%s/%s.%s.yaml', $translationsDir, $domainPrefix, $referenceLocale);
if (!is_file($referenceFile)) {
    fwrite(STDERR, "Reference catalogue not found: {$referenceFile}\n");
    exit(1);
}

try {
    $referenceKeys = array_keys(flattenKeys(Yaml::parseFile($referenceFile) ?? []));
} catch (ParseException $e) {
    fwrite(STDERR, "Failed to parse {$referenceFile}: {$e->getMessage()}\n");
    exit(1);
}

sort($referenceKeys);

$pattern = sprintf('%s/%s.*.yaml', $translationsDir, $domainPrefix);
$files = glob($pattern) ?: [];
if ($files === []) {
    fwrite(STDERR, "No translation catalogues matched {$pattern}\n");
    exit(1);
}

$failed = false;

foreach ($files as $file) {
    if (!preg_match('/\.([a-z]{2})\.yaml$/', $file, $matches)) {
        continue;
    }

    $locale = $matches[1];

    try {
        $keys = array_keys(flattenKeys(Yaml::parseFile($file) ?? []));
    } catch (ParseException $e) {
        fwrite(STDERR, "Failed to parse {$file}: {$e->getMessage()}\n");
        $failed = true;
        continue;
    }

    sort($keys);

    $missing = array_diff($referenceKeys, $keys);
    $extra = array_diff($keys, $referenceKeys);

    if ($missing !== [] || $extra !== []) {
        $failed = true;
        fwrite(STDERR, "Key mismatch in {$file}:\n");
        if ($missing !== []) {
            fwrite(STDERR, '  Missing keys: ' . implode(', ', $missing) . "\n");
        }
        if ($extra !== []) {
            fwrite(STDERR, '  Extra keys: ' . implode(', ', $extra) . "\n");
        }
    } else {
        echo "OK {$locale} (" . count($keys) . " keys)\n";
    }
}

exit($failed ? 1 : 0);

/**
 * @param array<string, mixed> $data
 *
 * @return array<string, string>
 */
function flattenKeys(array $data, string $prefix = ''): array
{
    $flat = [];

    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;

        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            $flat += flattenKeys($value, $path);
        } else {
            $flat[$path] = (string) $value;
        }
    }

    return $flat;
}
