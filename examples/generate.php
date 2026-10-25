#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Generate puzzle pieces from an image and export to a directory.
 *
 * Usage: php examples/generate.php <input> <rows> <cols> <output_dir> [options]
 *
 * Options:
 *   --config=<path>  Load edge style from JSON config (e.g. config/edge-config.json)
 *   --rounded        Use rounded corners (default radius: 4)
 *   --rounded=N      Use rounded corners with radius N
 *
 * If --config is given, it takes precedence over --rounded.
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(1);
}
require $autoload;

use ImagePuzzle\EdgeStyle\EdgeStyleFactory;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;
use ImagePuzzle\PuzzleGenerator;

// --- Parse arguments ---

$positional = [];
$configPath = null;
$rounded = null;

$argv = $_SERVER['argv'] ?? [];
if (!is_array($argv)) {
    $argv = [];
}
foreach (array_slice($argv, 1) as $arg) {
    if (!is_string($arg)) {
        continue;
    }
    if (str_starts_with($arg, '--config=')) {
        $configPath = substr($arg, strlen('--config='));
    } elseif (str_starts_with($arg, '--rounded')) {
        $rounded = ($arg === '--rounded') ? 4 : (int) (explode('=', $arg, 2)[1] ?? 4);
    } else {
        $positional[] = $arg;
    }
}

if (count($positional) < 4) {
    fwrite(STDERR, "Usage: php examples/generate.php <input> <rows> <cols> <output_dir> [--config=<path>] [--rounded[=N]]\n");
    exit(1);
}

[$inputPath, $rowsArg, $colsArg, $outputDir] = $positional;
$rows = (int) $rowsArg;
$cols = (int) $colsArg;

if ($rows < 1 || $cols < 1) {
    fwrite(STDERR, "Rows and cols must be at least 1.\n");
    exit(1);
}

// --- Resolve edge style ---

if ($configPath !== null) {
    $style = EdgeStyleFactory::fromFile($configPath);
} else {
    $style = $rounded !== null ? new RoundedCornersStyle($rounded) : new NoEdgeStyle();
}

$generator = new PuzzleGenerator($style);

try {
    $puzzle = $generator->generateFromFile($inputPath, $rows, $cols);
    $result = $puzzle->export($outputDir);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

$files = $result->getFiles();
echo 'Exported ' . (count($files) - 1) . ' pieces to ' . $result->getOutputDirectory() . "\n";
echo 'Manifest: ' . $result->getManifestPath() . "\n";
