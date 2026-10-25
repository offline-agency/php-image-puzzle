#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Regenerates the offline player example assets in examples/player/assets:
 * a synthetic picture, one game definition per mode and the jigsaw pieces,
 * plus three DefinitionBuilder jigsaw builds of the same puzzle: default PNG
 * pieces (jigsaw-png/), WebP pieces in three resolutions (jigsaw-responsive/)
 * and PNG pieces with an atlas (jigsaw-atlas/).
 *
 * Usage: php examples/player/generate.php
 */

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(1);
}
require $autoload;

use ImagePuzzle\Config\PuzzleConfig;
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameFactory;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\SourceImage;
use ImagePuzzle\PuzzleGenerator;

$assets = __DIR__ . '/assets';
if (!is_dir($assets) && !mkdir($assets, 0755, true)) {
    fwrite(STDERR, "Cannot create {$assets}\n");
    exit(1);
}

// A picture with distinct regions and numbers, so every tile is recognisable.
$width = 640;
$height = 480;
$image = imagecreatetruecolor($width, $height);
for ($y = 0; $y < $height; $y++) {
    $red = max(0, min(255, (int) (40 + 150 * $y / $height)));
    $blue = max(0, min(255, (int) (200 - 120 * $y / $height)));
    $color = (int) imagecolorallocate($image, $red, 90, $blue);
    imageline($image, 0, $y, $width - 1, $y, $color);
}
$palette = [[242, 201, 76], [235, 87, 87], [111, 207, 151], [86, 204, 242], [187, 107, 217], [255, 255, 255]];
foreach ($palette as $i => [$r, $g, $b]) {
    $color = (int) imagecolorallocate($image, $r, $g, $b);
    imagefilledellipse($image, 80 + $i * 100, 120 + ($i % 2) * 220, 150, 150, $color);
}
$white = (int) imagecolorallocate($image, 255, 255, 255);
$black = (int) imagecolorallocate($image, 20, 20, 30);
for ($row = 0; $row < 4; $row++) {
    for ($col = 0; $col < 4; $col++) {
        $x = $col * 160 + 70;
        $y = $row * 120 + 52;
        imagefilledrectangle($image, $x - 6, $y - 4, $x + 24, $y + 18, $black);
        imagestring($image, 5, $x, $y, (string) ($row * 4 + $col + 1), $white);
    }
}
imagesetthickness($image, 6);
imagerectangle($image, 3, 3, $width - 4, $height - 4, $white);
$picture = $assets . '/picture.png';
imagepng($image, $picture, 9);

$factory = new GameFactory();
$source = SourceImage::fromFile($picture);
$definitions = [
    'sliding' => $factory->create(GameKind::Sliding, $source, 'picture.png', 'easy', seed: 1),
    'swap' => $factory->create(GameKind::Swap, $source, 'picture.png', 'medium', seed: 2),
    'rotation' => $factory->create(GameKind::Rotation, $source, 'picture.png', 'medium', seed: 3),
    'strips' => $factory->create(GameKind::Strips, $source, 'picture.png', 'easy', seed: 4),
];

foreach (['rect' => 'jigsaw', 'hex' => 'jigsaw-hex'] as $topology => $name) {
    $out = $assets . '/' . $name;
    $stale = glob($out . '/*');
    foreach ($stale === false ? [] : $stale as $file) {
        unlink($file);
    }
    $config = PuzzleConfig::fromArray([
        'configVersion' => 2,
        'rows' => 3,
        'cols' => 4,
        'edge' => ['type' => 'jigsaw'],
        'geometry' => ['seed' => 5, 'topology' => $topology],
    ]);
    $result = PuzzleGenerator::generateAndExport($picture, $out, 3, 4, $config->edgeStyle(), $config->exportOptions(), geometry: $config->geometryOptions());
    $manifest = json_decode((string) file_get_contents($result->getManifestPath()), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($manifest)) {
        throw new RuntimeException('Manifest is not an object.');
    }
    $manifestArray = [];
    foreach ($manifest as $key => $value) {
        $manifestArray[(string) $key] = $value;
    }
    $definitions[$name] = $factory->jigsawFromManifest($manifestArray, 'picture.png', 'easy', 6, $source->sha256, $name . '/');
}

foreach ($definitions as $name => $definition) {
    file_put_contents($assets . '/' . $name . '.json', $definition->toJson() . "\n");
    echo "{$name}: {$definition->id}\n";
}

$builds = [
    'jigsaw-png' => new AssetOptions(),
    'jigsaw-responsive' => new AssetOptions('webp', null, ['thumbnail' => 160, 'mobile' => 320]),
    'jigsaw-atlas' => new AssetOptions(atlas: AssetOptions::ATLAS_FULL),
];
foreach ($builds as $name => $options) {
    $out = $assets . '/' . $name;
    if (is_dir($out)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($out, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
        }
    }
    $result = (new DefinitionBuilder())->build($picture, $out, GameKind::Jigsaw, 'custom', ['rows' => 3, 'cols' => 4, 'snap' => 24], 5, $options);
    echo "{$name}: {$result->definition->id}\n";
}
