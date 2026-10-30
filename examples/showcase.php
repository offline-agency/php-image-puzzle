#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds the README images in docs/images/showcase from a picture this script
 * draws itself (no third-party image, so provenance and licence are those of
 * the repository):
 *
 *   source.png               the generated picture
 *   exploded-<topology>.png  every real piece of a rect, hex and triangle
 *                            jigsaw, pulled apart from its home position
 *   reassembled.png          the rect pieces composited back additively at
 *                            their rendered positions (equal to the source
 *                            up to rounding)
 *   overview.png             source | a few pieces | exploded | reassembled
 *
 * Usage: php examples/showcase.php [output-dir]
 * The output is deterministic for a given GD build; tests/Docs/ShowcaseTest.php
 * compares the committed images with a fresh run.
 */

use ImagePuzzle\Config\PuzzleConfig;
use ImagePuzzle\PuzzleGenerator;

require dirname(__DIR__) . '/vendor/autoload.php';

$out = $argv[1] ?? dirname(__DIR__) . '/docs/images/showcase';
if (!is_dir($out) && !mkdir($out, 0o755, true) && !is_dir($out)) {
    fwrite(STDERR, "Cannot create {$out}\n");
    exit(1);
}

const SHOWCASE_WIDTH = 720;
const SHOWCASE_HEIGHT = 480;

function showcaseColor(GdImage $image, int $r, int $g, int $b, int $alpha = 0): int
{
    $color = imagecolorallocatealpha($image, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)), max(0, min(127, $alpha)));
    if ($color === false) {
        throw new RuntimeException('Cannot allocate a colour.');
    }

    return $color;
}

function showcaseCanvas(int $width, int $height, bool $transparent = false): GdImage
{
    $image = imagecreatetruecolor(max(1, $width), max(1, $height));
    if ($image === false) {
        throw new RuntimeException('Cannot create an image.');
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $transparent ? showcaseColor($image, 0, 0, 0, 127) : showcaseColor($image, 244, 241, 236));
    imagealphablending($image, true);

    return $image;
}

/** A landscape with enough detail that every piece is recognisable. */
function showcasePicture(): GdImage
{
    $image = showcaseCanvas(SHOWCASE_WIDTH, SHOWCASE_HEIGHT);
    for ($y = 0; $y < 300; $y++) {
        $t = $y / 300;
        imageline($image, 0, $y, SHOWCASE_WIDTH - 1, $y, showcaseColor($image, (int) (64 + 150 * $t), (int) (120 + 90 * $t), (int) (210 - 40 * $t)));
    }
    imagefilledellipse($image, 560, 110, 120, 120, showcaseColor($image, 255, 214, 92));
    foreach ([[90, 70], [250, 50], [400, 95]] as [$cx, $cy]) {
        foreach ([[0, 0, 90, 40], [-35, 8, 60, 30], [38, 10, 60, 28]] as [$dx, $dy, $w, $h]) {
            imagefilledellipse($image, $cx + $dx, $cy + $dy, $w, $h, showcaseColor($image, 250, 250, 252, 20));
        }
    }
    $ranges = [
        [[0, 300], [120, 150], [240, 210], [360, 160], [480, 230], [600, 140], [720, 200], [720, 300]],
        [[0, 300], [90, 230], [200, 270], [330, 200], [470, 260], [590, 210], [720, 250], [720, 300]],
    ];
    foreach ($ranges as $index => $points) {
        $flat = [];
        foreach ($points as [$x, $y]) {
            $flat[] = $x;
            $flat[] = $y;
        }
        imagefilledpolygon($image, $flat, $index === 0 ? showcaseColor($image, 92, 99, 130) : showcaseColor($image, 70, 112, 104));
    }
    for ($y = 300; $y < SHOWCASE_HEIGHT; $y++) {
        $t = ($y - 300) / 180;
        imageline($image, 0, $y, SHOWCASE_WIDTH - 1, $y, showcaseColor($image, (int) (40 + 30 * $t), (int) (150 - 40 * $t), (int) (170 - 90 * $t)));
    }
    for ($i = 0; $i < 9; $i++) {
        imageline($image, 40 + $i * 70, 330 + ($i % 3) * 40, 90 + $i * 70, 330 + ($i % 3) * 40, showcaseColor($image, 210, 235, 240, 40));
    }
    foreach ([[60, 395], [150, 420], [610, 400], [680, 430]] as [$x, $y]) {
        imagefilledrectangle($image, $x - 4, $y, $x + 4, $y + 40, showcaseColor($image, 102, 70, 45));
        imagefilledpolygon($image, [$x - 34, $y + 8, $x, $y - 70, $x + 34, $y + 8], showcaseColor($image, 34, 110, 62));
    }
    $house = showcaseColor($image, 214, 96, 72);
    imagefilledrectangle($image, 330, 370, 420, 440, showcaseColor($image, 236, 226, 205));
    imagefilledpolygon($image, [318, 372, 375, 325, 432, 372], $house);
    imagefilledrectangle($image, 362, 400, 386, 440, showcaseColor($image, 120, 72, 48));
    imagestring($image, 5, 24, SHOWCASE_HEIGHT - 26, 'php-image-puzzle', showcaseColor($image, 255, 255, 255));

    return $image;
}

/**
 * @return list<array{image: GdImage, x: int, y: int, row: int, col: int}>
 */
function showcasePieces(string $picture, string $dir, string $topology): array
{
    $config = PuzzleConfig::fromArray([
        'configVersion' => 2,
        'rows' => 3,
        'cols' => 4,
        'edge' => ['type' => 'jigsaw'],
        'geometry' => ['seed' => 11, 'topology' => $topology],
    ]);
    $result = PuzzleGenerator::generateAndExport($picture, $dir, 3, 4, $config->edgeStyle(), $config->exportOptions(), geometry: $config->geometryOptions());
    $pieces = [];
    $manifest = $result->getManifest();
    foreach (is_array($manifest['pieces'] ?? null) ? $manifest['pieces'] : [] as $piece) {
        if (!is_array($piece) || !is_array($piece['rendered'] ?? null) || !is_string($piece['filename'] ?? null)) {
            throw new RuntimeException('Unexpected manifest piece.');
        }
        $image = imagecreatefrompng($dir . '/' . $piece['filename']);
        $rendered = $piece['rendered'];
        if ($image === false || !is_int($rendered['x'] ?? null) || !is_int($rendered['y'] ?? null) || !is_int($piece['row'] ?? null) || !is_int($piece['col'] ?? null)) {
            throw new RuntimeException('Unreadable piece ' . $piece['filename']);
        }
        $pieces[] = ['image' => $image, 'x' => $rendered['x'], 'y' => $rendered['y'], 'row' => $piece['row'], 'col' => $piece['col']];
    }

    return $pieces;
}

/**
 * @param list<array{image: GdImage, x: int, y: int, row: int, col: int}> $pieces
 */
function showcaseExploded(array $pieces, float $spread): GdImage
{
    $margin = (int) ceil(max(SHOWCASE_WIDTH, SHOWCASE_HEIGHT) * ($spread - 1) / 2) + 24;
    $canvas = showcaseCanvas(SHOWCASE_WIDTH + 2 * $margin, SHOWCASE_HEIGHT + 2 * $margin);
    $shadow = showcaseColor($canvas, 0, 0, 0, 105);
    foreach ($pieces as $piece) {
        $w = imagesx($piece['image']);
        $h = imagesy($piece['image']);
        $cx = $piece['x'] + $w / 2 - SHOWCASE_WIDTH / 2;
        $cy = $piece['y'] + $h / 2 - SHOWCASE_HEIGHT / 2;
        $x = (int) round(SHOWCASE_WIDTH / 2 + $cx * $spread - $w / 2) + $margin;
        $y = (int) round(SHOWCASE_HEIGHT / 2 + $cy * $spread - $h / 2) + $margin;
        // A soft offset silhouette under each piece, from its own alpha channel.
        $silhouette = showcaseCanvas($w, $h, true);
        imagealphablending($silhouette, false);
        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                if (((imagecolorat($piece['image'], $px, $py) >> 24) & 0x7F) < 64) {
                    imagesetpixel($silhouette, $px, $py, $shadow);
                }
            }
        }
        imagecopy($canvas, $silhouette, $x + 4, $y + 5, 0, 0, $w, $h);
        imagecopy($canvas, $piece['image'], $x, $y, 0, 0, $w, $h);
    }

    return $canvas;
}

/**
 * The pieces composited back additively, as the geometry is designed
 * (manifest "compositing": "additive"): along a shared edge the antialiased
 * coverages of the two neighbours sum to one, so the seams disappear.
 *
 * @param list<array{image: GdImage, x: int, y: int, row: int, col: int}> $pieces
 */
function showcaseReassembled(array $pieces): GdImage
{
    $sum = array_fill(0, SHOWCASE_WIDTH * SHOWCASE_HEIGHT, [0.0, 0.0, 0.0, 0.0]);
    foreach ($pieces as $piece) {
        $w = imagesx($piece['image']);
        $h = imagesy($piece['image']);
        for ($py = 0; $py < $h; $py++) {
            $y = $piece['y'] + $py;
            for ($px = 0; $px < $w; $px++) {
                $x = $piece['x'] + $px;
                $rgba = imagecolorat($piece['image'], $px, $py);
                $alpha = (127 - (($rgba >> 24) & 0x7F)) / 127;
                if ($alpha <= 0.0 || $x < 0 || $y < 0 || $x >= SHOWCASE_WIDTH || $y >= SHOWCASE_HEIGHT) {
                    continue;
                }
                $cell = &$sum[$y * SHOWCASE_WIDTH + $x];
                $cell[0] += (($rgba >> 16) & 0xFF) * $alpha;
                $cell[1] += (($rgba >> 8) & 0xFF) * $alpha;
                $cell[2] += ($rgba & 0xFF) * $alpha;
                $cell[3] += $alpha;
                unset($cell);
            }
        }
    }
    $canvas = showcaseCanvas(SHOWCASE_WIDTH, SHOWCASE_HEIGHT);
    imagealphablending($canvas, false);
    foreach ($sum as $index => [$r, $g, $b, $a]) {
        if ($a <= 0.0) {
            continue;
        }
        $color = showcaseColor($canvas, (int) round($r / $a), (int) round($g / $a), (int) round($b / $a), (int) round(127 * (1 - min(1.0, $a))));
        imagesetpixel($canvas, $index % SHOWCASE_WIDTH, intdiv($index, SHOWCASE_WIDTH), $color);
    }

    return $canvas;
}

/**
 * Area-average resampling of an opaque image, in plain arithmetic: GD's
 * imagescale() differs between GD builds, which would make the committed
 * images depend on the machine that generated them.
 */
function showcaseScale(GdImage $image, int $width, int $height): GdImage
{
    $sw = imagesx($image);
    $sh = imagesy($image);
    $spans = static function (int $from, int $to): array {
        // For each target index, the source indices it covers and their weights.
        $ratio = $from / $to;
        $spans = [];
        for ($t = 0; $t < $to; $t++) {
            $start = $t * $ratio;
            $end = ($t + 1) * $ratio;
            $weights = [];
            for ($s = (int) floor($start); $s < min($from, (int) ceil($end)); $s++) {
                $weights[$s] = (min($end, $s + 1) - max($start, $s)) / $ratio;
            }
            $spans[] = $weights;
        }

        return $spans;
    };
    $columns = $spans($sw, $width);
    // Source row => [target row => weight], to stream the vertical pass.
    $contributions = [];
    foreach ($spans($sh, $height) as $ty => $weights) {
        foreach ($weights as $y => $weight) {
            $contributions[$y][$ty] = $weight;
        }
    }
    $scaled = showcaseCanvas($width, $height);
    imagealphablending($scaled, false);
    /** @var array<int, list<float>> $pending target row => r, g, b per column */
    $pending = [];
    for ($y = 0; $y < $sh; $y++) {
        $line = [];
        for ($x = 0; $x < $sw; $x++) {
            $line[] = imagecolorat($image, $x, $y);
        }
        // Horizontal pass of this source row.
        $row = [];
        foreach ($columns as $weights) {
            $r = $g = $b = 0.0;
            foreach ($weights as $x => $weight) {
                $r += (($line[$x] >> 16) & 0xFF) * $weight;
                $g += (($line[$x] >> 8) & 0xFF) * $weight;
                $b += ($line[$x] & 0xFF) * $weight;
            }
            array_push($row, $r, $g, $b);
        }
        foreach ($contributions[$y] ?? [] as $ty => $weight) {
            $acc = $pending[$ty] ?? array_fill(0, 3 * $width, 0.0);
            foreach ($row as $i => $value) {
                $acc[$i] += $value * $weight;
            }
            $pending[$ty] = $acc;
        }
        // Target rows that no later source row contributes to are complete.
        foreach (array_keys($pending) as $ty) {
            if (!isset($contributions[$y + 1][$ty])) {
                $acc = $pending[$ty];
                for ($tx = 0; $tx < $width; $tx++) {
                    imagesetpixel($scaled, $tx, $ty, ((int) round($acc[3 * $tx]) << 16) | ((int) round($acc[3 * $tx + 1]) << 8) | (int) round($acc[3 * $tx + 2]));
                }
                unset($pending[$ty]);
            }
        }
    }
    imagealphablending($scaled, true);

    return $scaled;
}

function showcaseFit(GdImage $image, int $width, int $height): GdImage
{
    $scale = min($width / imagesx($image), $height / imagesy($image));
    $scaled = showcaseScale($image, max(1, (int) round(imagesx($image) * $scale)), max(1, (int) round(imagesy($image) * $scale)));
    $panel = showcaseCanvas($width, $height);
    imagecopy($panel, $scaled, intdiv($width - imagesx($scaled), 2), intdiv($height - imagesy($scaled), 2), 0, 0, imagesx($scaled), imagesy($scaled));

    return $panel;
}

function showcaseSave(GdImage $image, string $path): void
{
    if (!imagepng($image, $path, 9)) {
        throw new RuntimeException("Cannot write {$path}");
    }
    echo $path, "\n";
}

$work = sys_get_temp_dir() . '/image-puzzle-showcase-' . bin2hex(random_bytes(6));
mkdir($work, 0o700);
try {
    $picture = showcasePicture();
    imagepng($picture, $work . '/source.png', 9);
    showcaseSave($picture, $out . '/source.png');

    $rect = [];
    foreach (['rect', 'hex', 'triangle'] as $topology) {
        $pieces = showcasePieces($work . '/source.png', $work . '/' . $topology, $topology);
        showcaseSave(showcaseFit(showcaseExploded($pieces, 1.45), 960, 680), "{$out}/exploded-{$topology}.png");
        if ($topology === 'rect') {
            $rect = $pieces;
        }
    }
    $reassembled = showcaseReassembled($rect);
    showcaseSave($reassembled, $out . '/reassembled.png');

    // A few real pieces, as they come out of the exporter.
    $selection = array_values(array_filter($rect, static fn (array $p): bool => in_array([$p['row'], $p['col']], [[0, 0], [0, 2], [1, 1], [1, 3], [2, 0], [2, 2]], true)));
    $tray = showcaseCanvas(620, 470);
    foreach ($selection as $i => $piece) {
        $w = imagesx($piece['image']);
        $h = imagesy($piece['image']);
        imagecopy($tray, $piece['image'], 18 + ($i % 3) * 200 + intdiv(200 - $w, 2), 12 + intdiv($i, 3) * 230 + intdiv(230 - $h, 2), 0, 0, $w, $h);
    }

    $panel = 360;
    $overview = showcaseCanvas(4 * $panel + 5 * 16, 270 + 40);
    $ink = showcaseColor($overview, 40, 40, 48);
    foreach ([['Source', $picture], ['Real pieces', $tray], ['Exploded', showcaseExploded($rect, 1.22)], ['Reassembled', $reassembled]] as $i => [$label, $image]) {
        $x = 16 + $i * ($panel + 16);
        imagecopy($overview, showcaseFit($image, $panel, 270), $x, 16, 0, 0, $panel, 270);
        imagestring($overview, 5, $x, 270 + 20, $label, $ink);
    }
    showcaseSave($overview, $out . '/overview.png');
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file instanceof SplFileInfo) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
    }
    rmdir($work);
}
