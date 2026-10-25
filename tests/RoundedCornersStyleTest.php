<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\RoundedCornersStyle;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\PieceContext;
use ImagePuzzle\PuzzleGenerator;

test('apply returns image with same dimensions', function (): void {
    $style = new RoundedCornersStyle(4);
    $width = 20;
    $height = 16;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100, 4, 4);
    $result = $style->apply($image, $context);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});

test('apply does not crash with small piece', function (): void {
    $style = new RoundedCornersStyle(2);
    $image = imagecreatetruecolor(4, 4);
    expect($image)->not->toBeFalse();
    $context = new PieceContext(0, 0, 0, 0, 4, 4, 32, 32, 2, 2);
    $result = $style->apply($image, $context);
    expect($result)->toBeInstanceOf(GdImage::class);
});

test('radius zero throws', function (): void {
    expect(fn () => new RoundedCornersStyle(0))
        ->toThrow(\InvalidArgumentException::class, 'Radius must be at least 1');
});

test('radius one is valid', function (): void {
    $style = new RoundedCornersStyle(1);
    $image = imagecreatetruecolor(10, 10);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new PieceContext(0, 0, 0, 0, 10, 10, 100, 100, 4, 4);
    $result = $style->apply($image, $context);
    expect(imagesx($result))->toBe(10);
    expect(imagesy($result))->toBe(10);
});

test('apply clamps radius >= half of smaller dimension instead of skipping', function (): void {
    $style = new RoundedCornersStyle(10);
    $width = 16;
    $height = 20;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100, 4, 4);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});

test('negative radius throws', function (): void {
    expect(fn () => new RoundedCornersStyle(-1))
        ->toThrow(\InvalidArgumentException::class, 'Radius must be at least 1');
});

test('integration with PuzzleGenerator and export', function (): void {
    $generator = new PuzzleGenerator(new RoundedCornersStyle(3));
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $outDir = sys_get_temp_dir() . '/image-puzzle-rounded-' . uniqid();
    $result = $puzzle->export($outDir, new ExportOptions());
    expect($result->getFiles())->toHaveCount(5);
    foreach ($result->getFiles() as $path) {
        expect($path)->toBeFile();
    }
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('mask-based corners: 8x8 piece radius 2 has transparent corner pixels', function (): void {
    $style = new RoundedCornersStyle(2);
    $image = imagecreatetruecolor(8, 8);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    imagealphablending($image, false);
    $opaque = imagecolorallocatealpha($image, 100, 100, 100, 0);
    if ($opaque !== false) {
        imagefill($image, 0, 0, $opaque);
    }
    imagealphablending($image, true);
    $context = new PieceContext(0, 0, 0, 0, 8, 8, 32, 32, 2, 2);
    $result = $style->apply($image, $context);
    imagesavealpha($result, true);
    $alphaAt = static function (GdImage $img, int $x, int $y): int {
        $c = imagecolorat($img, $x, $y);
        return ($c >> 24) & 0x7F;
    };
    // Radius 2, pixel centres: only the outermost corner pixel lies outside each arc.
    expect($alphaAt($result, 0, 0))->toBe(127);
    expect($alphaAt($result, 1, 0))->toBe(0);
    expect($alphaAt($result, 0, 1))->toBe(0);
    expect($alphaAt($result, 7, 0))->toBe(127);
    expect($alphaAt($result, 0, 7))->toBe(127);
    expect($alphaAt($result, 7, 7))->toBe(127);
});

test('clearMaskCache can be called and apply still works after', function (): void {
    RoundedCornersStyle::clearMaskCache();
    $style = new RoundedCornersStyle(2);
    $image = imagecreatetruecolor(8, 8);
    expect($image)->not->toBeFalse();
    $context = new PieceContext(0, 0, 0, 0, 8, 8, 32, 32, 2, 2);
    $result = $style->apply($image, $context);
    expect($result)->toBeInstanceOf(\GdImage::class);
    expect(imagesx($result))->toBe(8);
    expect(imagesy($result))->toBe(8);
});
