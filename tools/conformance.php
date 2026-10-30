<?php

declare(strict_types=1);

/*
 * Writes (default) or checks (--check) the PHP/TypeScript conformance
 * fixtures in conformance/. Usage: composer conformance [-- --check]
 */

use ImagePuzzle\Tools\ConformanceFixtures;

require dirname(__DIR__) . '/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? [];
$check = is_array($arguments) && in_array('--check', $arguments, true);
$dir = dirname(__DIR__) . '/conformance';
if (!$check && !is_dir($dir) && !mkdir($dir, 0755)) {
    fwrite(STDERR, "Cannot create {$dir}\n");
    exit(1);
}
$stale = 0;
foreach (ConformanceFixtures::build() as $name => $document) {
    $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $path = "{$dir}/{$name}";
    if ($check) {
        if (!is_file($path) || file_get_contents($path) !== $json) {
            echo "stale conformance/{$name}\n";
            $stale++;
        }
        continue;
    }
    file_put_contents($path, $json);
    echo "wrote conformance/{$name}\n";
}
if ($stale > 0) {
    fwrite(STDERR, "{$stale} fixture(s) differ from the PHP rules; run composer conformance and commit the result.\n");
    exit(1);
}
