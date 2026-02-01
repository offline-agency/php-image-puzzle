#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate example piece images for each edge type (for documentation).
 *
 * Usage: php examples/generate-edge-samples.php [input_image] [output_base_dir]
 *
 *   input_image    Path to source image (default: tests/fixtures/sample.png)
 *   output_base_dir Base directory for edge-examples (default: docs/images/edge-examples)
 *
 * Exports a 2x2 puzzle per edge type into <output_base_dir>/<type>/ (e.g. jigsaw/piece_0_0.png).
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(1);
}
require $autoload;

use ImagePuzzle\EdgeStyle\EdgeStyleFactory;
use ImagePuzzle\PuzzleGenerator;

$baseDir = dirname(__DIR__);
$inputPath = $argv[1] ?? $baseDir . '/tests/fixtures/sample.png';
$outputBase = $argv[2] ?? $baseDir . '/docs/images/edge-examples';

$edgeTypes = [
    'no_edge',
    'line',
    'jigsaw',
    'arc',
    'half_circle',
    'sinus',
    'rounded_corners',
];

$rows = 2;
$cols = 2;

foreach ($edgeTypes as $type) {
    $options = $type === 'rounded_corners' ? ['options' => ['radius' => 6]] : [];
    $config = ['edge' => array_merge(['type' => $type], $options)];

    $style = EdgeStyleFactory::fromArray($config);
    $generator = new PuzzleGenerator($style);

    $outDir = $outputBase . '/' . $type;
    if (!is_dir($outDir)) {
        if (!@mkdir($outDir, 0755, true)) {
            fwrite(STDERR, "Failed to create directory: {$outDir}\n");
            exit(1);
        }
    }

    try {
        $puzzle = $generator->generateFromFile($inputPath, $rows, $cols);
        $result = $puzzle->export($outDir);
        echo "Exported {$type} to {$outDir}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "Error for {$type}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "Done. Example images are in {$outputBase}\n";
