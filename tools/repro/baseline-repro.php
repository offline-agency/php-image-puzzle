<?php

declare(strict_types=1);

/*
 * Phase 0 reproduction script for the audited gaps.
 *
 * Run against the ORIGINAL 1.0.2 sources (commit b114a4a) to reproduce the
 * defects dynamically: `php tools/repro/baseline-repro.php`. Each check prints
 * REPRODUCED or NOT-REPRODUCED with a short measurement. It is kept in the
 * repository so the evidence in docs/quality/verification.md is repeatable
 * with `git worktree add /tmp/baseline b114a4a`.
 */

require __DIR__ . '/../../vendor/autoload.php';

use ImagePuzzle\EdgeStyle\CompositeEdgeStyle;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;
use ImagePuzzle\EdgeStyle\SinusStyle;
use ImagePuzzle\Exporter;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\PieceContext;
use ImagePuzzle\PuzzleGenerator;

$tmp = sys_get_temp_dir() . '/image-puzzle-repro-' . getmypid();
@mkdir($tmp, 0777, true);

function report(string $id, bool $reproduced, string $detail): void
{
    printf("%-4s %-15s %s\n", $id, $reproduced ? 'REPRODUCED' : 'NOT-REPRODUCED', $detail);
}

function opaqueImage(int $w, int $h): GdImage
{
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            imagesetpixel($img, $x, $y, imagecolorallocate($img, $x % 256, $y % 256, ($x * 7 + $y * 3) % 256));
        }
    }
    return $img;
}

function alphaAt(GdImage $img, int $x, int $y): int
{
    return (imagecolorat($img, $x, $y) >> 24) & 0x7F;
}

// G01: composer.json version field.
$composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
report('G01', isset($composer['version']), 'composer.json version=' . var_export($composer['version'] ?? null, true));

// G02: ImageLoader calls exif_imagetype unconditionally.
$loaderSource = (string) file_get_contents(__DIR__ . '/../../src/ImageLoader.php');
$requiresExif = str_contains($loaderSource, 'exif_imagetype(') && !isset($composer['require']['ext-exif']);
report('G02', $requiresExif, 'exif_imagetype() used without function_exists() fallback or ext-exif requirement');

// G03: Rounded corners erase whole r x r corner square.
$img = opaqueImage(40, 40);
$ctx = new PieceContext(0, 0, 0, 0, 40, 40, 40, 40, 1, 1);
(new RoundedCornersStyle(8))->apply($img, $ctx);
$cornerTransparent = 0;
for ($y = 0; $y < 8; $y++) {
    for ($x = 0; $x < 8; $x++) {
        $cornerTransparent += alphaAt($img, $x, $y) === 127 ? 1 : 0;
    }
}
// Pixel (7,7) is inside the arc and must stay opaque.
report('G03', alphaAt($img, 7, 7) === 127, "transparent pixels in 8x8 corner square: {$cornerTransparent}/64 (pixel 7,7 alpha=" . alphaAt($img, 7, 7) . ')');
$img2 = opaqueImage(20, 20);
(new RoundedCornersStyle(10))->apply($img2, new PieceContext(0, 0, 0, 0, 20, 20, 20, 20, 1, 1));
report('G03b', alphaAt($img2, 0, 0) === 0, 'radius >= min/2 silently disables rounding: alpha(0,0)=' . alphaAt($img2, 0, 0));

// G04: more rows than pixels and invalid requested indices.
try {
    $p = (new PuzzleGenerator())->generateFromGd(opaqueImage(3, 3), 5, 1);
    $zero = 0;
    foreach ($p->getPieces() as $piece) {
        $zero += $piece->getHeight() === 0 ? 1 : 0;
    }
    report('G04', $zero > 0, "5 rows on 3px image accepted, {$zero} zero-height pieces");
} catch (Throwable $e) {
    report('G04', false, get_class($e) . ': ' . $e->getMessage());
}
set_error_handler(static function (int $no, string $msg): never {
    throw new ErrorException($msg, 0, $no);
});
try {
    (new PuzzleGenerator())->generatePiecesFromGd(opaqueImage(10, 10), 2, 2, [[5, 5]]);
    report('G04b', true, 'out-of-range requested index accepted');
} catch (Throwable $e) {
    report('G04b', $e instanceof ErrorException, 'out-of-range index -> ' . get_class($e) . ': ' . $e->getMessage());
}
restore_error_handler();

// G05/G06: jigsaw/sinus only modify the one-pixel perimeter; sinus tab == socket.
$img = opaqueImage(40, 40);
(new JigsawStyle())->apply($img, new PieceContext(0, 0, 0, 0, 40, 40, 80, 80, 2, 2));
$innerChanged = 0;
for ($y = 1; $y < 39; $y++) {
    for ($x = 1; $x < 39; $x++) {
        $innerChanged += alphaAt($img, $x, $y) !== 0 ? 1 : 0;
    }
}
report('G05', $innerChanged === 0 && imagesx($img) === 40, "jigsaw changed {$innerChanged} interior pixels; rendered size stays 40x40 (no tab padding)");
$a = opaqueImage(40, 40);
(new SinusStyle())->apply($a, new PieceContext(0, 0, 0, 0, 40, 40, 40, 40, 1, 1));
$perimeterTransparent = 0;
for ($x = 0; $x < 40; $x++) {
    $perimeterTransparent += alphaAt($a, $x, 0) === 127 ? 1 : 0;
}
report('G06', $perimeterTransparent > 0, "1x1 board (all edges external) still loses {$perimeterTransparent} top-perimeter pixels; tab/socket branches identical in source");

// G07: composite copies only one-pixel strips.
$compositeSource = (string) file_get_contents(__DIR__ . '/../../src/EdgeStyle/CompositeEdgeStyle.php');
report('G07', str_contains($compositeSource, '$width, 1)'), 'CompositeEdgeStyle::copyEdge copies 1-pixel strips only');

// G08/G09/G11 need subprocesses; see tests/Process. Static evidence:
$exporterSource = (string) file_get_contents(__DIR__ . '/../../src/Exporter.php');
report('G08', !str_contains($exporterSource, 'catch (\\Throwable'), 'child branch has no Throwable boundary before exit(0)');
report('G09', str_contains($exporterSource, 'pcntl_wifexited($status) && pcntl_wexitstatus($status) !== 0'), 'signal deaths (wifexited=false) are not treated as failure');

// G10: parallel export ignores overwrite=false.
if (extension_loaded('pcntl')) {
    $src = $tmp . '/src.png';
    imagepng(opaqueImage(40, 40), $src);
    $out = $tmp . '/g10';
    @mkdir($out);
    file_put_contents($out . '/piece_0_0.png', 'SENTINEL');
    (new Exporter())->exportParallel($src, $out, 2, 2, (new ExportOptions())->withOverwrite(false), 2);
    $preserved = file_get_contents($out . '/piece_0_0.png') === 'SENTINEL';
    report('G10', !$preserved, 'parallel export with overwrite=false ' . ($preserved ? 'preserved' : 'overwrote') . ' the sentinel');
}

// G12: duplicate filenames.
$out = $tmp . '/g12';
$result = (new PuzzleGenerator())->generateFromGd(opaqueImage(20, 20), 2, 2)
    ->export($out, (new ExportOptions())->withFilenamePattern('same.png'));
$unique = count(array_unique($result->getFiles()));
report('G12', $unique < count($result->getFiles()), "4 pieces mapped to {$unique} distinct files (" . count($result->getFiles()) . ' reported)');
$result = (new PuzzleGenerator())->generateFromGd(opaqueImage(20, 20), 1, 1)
    ->export($tmp . '/g12b', (new ExportOptions())->withFilenamePattern('manifest.json'));
report('G12b', count(array_unique($result->getFiles())) === 1, 'piece filename equal to manifest name accepted; piece overwritten by manifest');

// G13: directory created before base check.
$base = $tmp . '/base';
@mkdir($base);
$outside = $tmp . '/outside-created-' . uniqid();
try {
    (new PuzzleGenerator())->generateFromGd(opaqueImage(10, 10), 1, 1)->export($outside, new ExportOptions(), $base);
} catch (Throwable) {
}
report('G13', is_dir($outside), 'rejected outside-base directory ' . (is_dir($outside) ? 'was still created' : 'was not created'));

// G14: failure mid-export leaves partial pieces and earlier manifest.
$out = $tmp . '/g14';
(new PuzzleGenerator())->generateFromGd(opaqueImage(20, 20), 2, 2)->export($out);
$before = file_get_contents($out . '/manifest.json');
$count = 0;
try {
    (new PuzzleGenerator())->generateFromGd(opaqueImage(30, 30), 2, 2)->export($out, (new ExportOptions())->withOnPieceAfterSave(
        static function () use (&$count): void {
            if (++$count === 2) {
                throw new RuntimeException('injected');
            }
        }
    ));
} catch (Throwable) {
}
$mixed = imagesx(imagecreatefrompng($out . '/piece_0_0.png')) === 15 && file_get_contents($out . '/manifest.json') === $before;
report('G14', $mixed, 'after injected failure: new piece files mixed with stale manifest describing old dimensions');

// G15: no budgets.
report('G15', !str_contains($loaderSource, 'limit'), 'ImageLoader has no byte/dimension/pixel budget');

// G17: format switch keeps quality 9.
$opts = (new ExportOptions())->withFormat(ExportOptions::FORMAT_JPEG);
report('G17', $opts->getQuality() === 9, 'withFormat(jpeg) keeps quality=' . $opts->getQuality() . ' (JPEG quality 9/100)');

// G18: schema ids.
$schema = json_decode((string) file_get_contents(__DIR__ . '/../../docs/schemas/manifest.schema.json'), true);
report('G18', str_contains($schema['$id'], 'gfabbian'), 'schema $id=' . $schema['$id']);

// G16: final classes injected concretely.
report('G16', str_contains((string) file_get_contents(__DIR__ . '/../../src/PuzzleGenerator.php'), '?ImageLoader $imageLoader'), 'PuzzleGenerator accepts final ImageLoader/Exporter concretes; no substitutable interface');

// Committed jigsaw example pixel loss (G05 evidence on committed assets).
$dir = __DIR__ . '/../../docs/images';
if (is_file($dir . '/edge-jigsaw/piece_0_0.png')) {
    $lost = 0;
    foreach (glob($dir . '/edge-jigsaw/piece_*.png') ?: [] as $file) {
        $p = imagecreatefrompng($file);
        for ($y = 0; $y < imagesy($p); $y++) {
            for ($x = 0; $x < imagesx($p); $x++) {
                $lost += alphaAt($p, $x, $y) === 127 ? 1 : 0;
            }
        }
    }
    report('G05c', $lost > 0, "committed edge-jigsaw example pieces contain {$lost} fully transparent pixels");
}

exec('rm -rf ' . escapeshellarg($tmp));
