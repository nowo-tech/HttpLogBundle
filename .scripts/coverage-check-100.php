#!/usr/bin/env php
<?php

declare(strict_types=1);

if (!is_file('coverage.xml')) {
    fwrite(STDERR, "ERROR: coverage.xml file was not generated\n");
    exit(1);
}

$coverage = simplexml_load_file('coverage.xml');
if (false === $coverage) {
    fwrite(STDERR, "ERROR: Could not read coverage.xml\n");
    exit(1);
}

$metrics = $coverage->project->metrics;
$elements = (float) $metrics['elements'];
$coveredElements = (float) $metrics['coveredelements'];

if (0.0 === $elements) {
    echo "No elements to cover\n";
    exit(0);
}

$percentage = ($coveredElements / $elements) * 100;
echo sprintf("Coverage: %.0f/%.0f (%.2f%%)\n", $coveredElements, $elements, $percentage);

if ($percentage < 100) {
    fwrite(STDERR, sprintf("ERROR: Coverage must be 100%%. Current: %.2f%%\n", $percentage));
    exit(1);
}

echo "✅ 100% coverage confirmed\n";
