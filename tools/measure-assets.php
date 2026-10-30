<?php

declare(strict_types=1);

/*
 * Measures what a browser fetches and decodes to show a jigsaw board, for
 * the piece image options (docs/EXPORT.md, "Choosing piece images"):
 *
 *   php tools/measure-assets.php [width] [rows] [cols]
 *
 * The input is a deterministic synthetic picture (gradients, shapes and
 * noise) of the given width at 4:3, so results are reproducible but only
 * indicative: real photos compress differently. For each build it prints
 * the image requests the player makes for one board, their bytes, and the
 * decoded RGBA memory (4 bytes per pixel) of those images plus, for atlas
 * builds, the per-piece canvases the player draws frames into.
 */

use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\Doc;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\PuzzleDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$width = max(160, min(8000, (int) ($argv[1] ?? 1920)));
$rows = max(2, min(20, (int) ($argv[2] ?? 4)));
$cols = max(2, min(20, (int) ($argv[3] ?? 6)));
$height = max(120, intdiv($width * 3, 4));

$work = sys_get_temp_dir() . '/image-puzzle-measure-' . bin2hex(random_bytes(6));
mkdir($work, 0o700);

mt_srand(20261025);
$image = imagecreatetruecolor($width, $height);
for ($y = 0; $y < $height; $y++) {
    $shade = max(0, min(255, intdiv(255 * $y, $height)));
    $c = (int) imagecolorallocate($image, $shade, 120, 255 - $shade);
    imageline($image, 0, $y, $width - 1, $y, $c);
}
for ($i = 0; $i < 60; $i++) {
    $c = (int) imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
    imagefilledellipse($image, mt_rand(0, $width), mt_rand(0, $height), mt_rand(40, intdiv($width, 4)), mt_rand(40, intdiv($height, 4)), $c);
}
for ($i = 0; $i < $width * $height / 8; $i++) {
    $x = mt_rand(0, $width - 1);
    $y = mt_rand(0, $height - 1);
    $rgb = imagecolorat($image, $x, $y);
    $d = mt_rand(-24, 24);
    $r = max(0, min(255, (($rgb >> 16) & 255) + $d));
    $g = max(0, min(255, (($rgb >> 8) & 255) + $d));
    $b = max(0, min(255, ($rgb & 255) + $d));
    imagesetpixel($image, $x, $y, (int) imagecolorallocate($image, $r, $g, $b));
}
imagepng($image, "{$work}/photo.png");

$builds = [
    'PNG pieces (default)' => [new AssetOptions(), null],
    'WebP pieces' => [new AssetOptions(format: 'webp'), null],
    'PNG pieces, mobile set (1080)' => [new AssetOptions(resolutions: ['mobile']), 'mobile'],
    'WebP pieces, thumbnail set (320)' => [new AssetOptions(format: 'webp', resolutions: ['thumbnail']), 'thumbnail'],
    'PNG atlas (full)' => [new AssetOptions(atlas: 'full'), null],
    'WebP atlas (full)' => [new AssetOptions(format: 'webp', atlas: 'full'), null],
    'WebP atlas (mobile set)' => [new AssetOptions(format: 'webp', resolutions: ['mobile'], atlas: 'mobile'), null],
];

echo "Synthetic {$width}x{$height} picture, {$rows}x{$cols} jigsaw, PHP " . PHP_VERSION . ', GD ' . GD_VERSION . "\n\n";
echo "| Build | Image requests | Image bytes | Decoded memory |\n| --- | ---: | ---: | ---: |\n";
try {
    $i = 0;
    foreach ($builds as $label => [$assets, $set]) {
        $dir = "{$work}/build-" . $i++;
        (new DefinitionBuilder())->build("{$work}/photo.png", $dir, GameKind::Jigsaw, 'custom', ['rows' => $rows, 'cols' => $cols], 1, $assets);
        $puzzle = PuzzleDirectory::open($dir);
        $definition = $puzzle->definition;
        $requests = 0;
        $bytes = 0;
        $decoded = 0;
        $atlas = $definition->pieceImages->atlas;
        if ($atlas !== null) {
            $requests = 1;
            $bytes = (int) filesize($puzzle->file($atlas->image));
            $decoded = $atlas->width * $atlas->height * 4;
            foreach ($atlas->frames as $frame) {
                // Each frame is drawn into a canvas of the frame size.
                $decoded += $frame->width * $frame->height * 4;
            }
        } else {
            $directory = null;
            foreach ($definition->pieceImages->resolutions as $resolution) {
                if ($resolution->name === $set) {
                    $directory = $resolution->directory;
                }
            }
            foreach ($definition->tiles as $tile) {
                $file = Doc::stringValue($tile->attributes['image'] ?? null, 'tile image');
                $path = $puzzle->file($directory === null ? $file : $directory . '/' . basename($file));
                $size = getimagesize($path);
                $requests++;
                $bytes += (int) filesize($path);
                $decoded += $size === false ? 0 : $size[0] * $size[1] * 4;
            }
        }
        printf("| %s | %d | %s | %s |\n", $label, $requests, number_format($bytes / 1024, 0) . ' KiB', number_format($decoded / 1048576, 1) . ' MiB');
    }
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        if ($entry instanceof SplFileInfo) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    }
    rmdir($work);
}
