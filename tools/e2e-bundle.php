<?php

declare(strict_types=1);

/*
 * Prepares extracted ZIP bundles for the browser tests
 * (packages/player/e2e/bundle.spec.ts):
 *
 *   php tools/e2e-bundle.php <out-dir>                 build from source
 *   php tools/e2e-bundle.php <out-dir> <a.zip> [...]   extract given bundles
 *
 * Without archives it builds two jigsaw bundles from the example picture,
 * "pieces" (one PNG per piece) and "atlas" (WebP atlas plus a thumbnail
 * set), with BundleExporter. Each archive is extracted into
 * <out-dir>/<archive name>/ after checking that every entry stays inside it.
 * Prints the extracted index.html paths, one per line.
 */

use ImagePuzzle\Export\BundleExporter;
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\PuzzleDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        if ($entry instanceof SplFileInfo) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    }
    rmdir($dir);
}

$out = $argv[1] ?? fail('usage: php tools/e2e-bundle.php <out-dir> [bundle.zip ...]');
$archives = array_slice($argv, 2);
removeTree($out);
mkdir($out, 0o755, true);

$work = sys_get_temp_dir() . '/image-puzzle-e2e-' . bin2hex(random_bytes(6));
mkdir($work, 0o700);
try {
    if ($archives === []) {
        $picture = dirname(__DIR__) . '/examples/player/assets/picture.png';
        $builds = ['pieces' => new AssetOptions(), 'atlas' => new AssetOptions(format: 'webp', resolutions: ['thumbnail'], atlas: 'full')];
        foreach ($builds as $name => $assets) {
            (new DefinitionBuilder())->build($picture, "{$work}/{$name}", GameKind::Jigsaw, 'custom', ['rows' => 3, 'cols' => 4], 5, $assets);
            (new BundleExporter())->write(PuzzleDirectory::open("{$work}/{$name}"), "{$work}/{$name}.zip", 'image-puzzle', "Bundle {$name}");
            $archives[] = "{$work}/{$name}.zip";
        }
    }
    foreach ($archives as $archive) {
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            fail("Cannot open {$archive}.");
        }
        $target = $out . '/' . basename($archive, '.zip');
        $index = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || str_starts_with($name, '/') || str_contains($name, '\\') || preg_match('~(^|/)\.\.?(/|$)~', $name) === 1) {
                fail("Unsafe entry {$name} in {$archive}.");
            }
            if (str_ends_with($name, '/index.html') && substr_count($name, '/') === 1) {
                $index = $name;
            }
        }
        if ($index === null || !$zip->extractTo($target)) {
            fail("{$archive} has no <folder>/index.html or cannot be extracted.");
        }
        $zip->close();
        echo realpath("{$target}/{$index}") . "\n";
    }
} finally {
    removeTree($work);
}
