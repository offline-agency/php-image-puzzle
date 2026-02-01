<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\ArcStyle;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\HalfCircleStyle;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\LineStyle;
use ImagePuzzle\EdgeStyle\SinusStyle;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\PieceContext;
use ImagePuzzle\PuzzleGenerator;

test('LineStyle implements EdgeStyleInterface', function (): void {
    expect(new LineStyle())->toBeInstanceOf(EdgeStyleInterface::class);
});

test('LineStyle apply returns same image with unchanged dimensions', function (): void {
    $style = new LineStyle();
    $width = 16;
    $height = 12;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});

test('JigsawStyle implements EdgeStyleInterface', function (): void {
    expect(new JigsawStyle())->toBeInstanceOf(EdgeStyleInterface::class);
});

test('JigsawStyle apply returns image with same dimensions', function (): void {
    $style = new JigsawStyle();
    $width = 20;
    $height = 16;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});

test('ArcStyle implements EdgeStyleInterface', function (): void {
    expect(new ArcStyle())->toBeInstanceOf(EdgeStyleInterface::class);
});

test('ArcStyle apply returns image with same dimensions', function (): void {
    $style = new ArcStyle();
    $width = 18;
    $height = 14;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});

test('HalfCircleStyle implements EdgeStyleInterface', function (): void {
    expect(new HalfCircleStyle())->toBeInstanceOf(EdgeStyleInterface::class);
});

test('HalfCircleStyle apply returns image with same dimensions', function (): void {
    $style = new HalfCircleStyle();
    $width = 16;
    $height = 12;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});

test('SinusStyle implements EdgeStyleInterface', function (): void {
    expect(new SinusStyle())->toBeInstanceOf(EdgeStyleInterface::class);
});

test('SinusStyle apply returns image with same dimensions', function (): void {
    $style = new SinusStyle();
    $width = 20;
    $height = 16;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});

test('JigsawStyle integration with PuzzleGenerator and export', function (): void {
    $generator = new PuzzleGenerator(new JigsawStyle());
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $outDir = sys_get_temp_dir() . '/image-puzzle-jigsaw-' . uniqid();
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

test('ArcStyle integration with PuzzleGenerator and export', function (): void {
    $generator = new PuzzleGenerator(new ArcStyle());
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $outDir = sys_get_temp_dir() . '/image-puzzle-arc-' . uniqid();
    $result = $puzzle->export($outDir, new ExportOptions());
    expect($result->getFiles())->toHaveCount(5);
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('HalfCircleStyle integration with PuzzleGenerator and export', function (): void {
    $generator = new PuzzleGenerator(new HalfCircleStyle());
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $outDir = sys_get_temp_dir() . '/image-puzzle-halfcircle-' . uniqid();
    $result = $puzzle->export($outDir, new ExportOptions());
    expect($result->getFiles())->toHaveCount(5);
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('SinusStyle integration with PuzzleGenerator and export', function (): void {
    $generator = new PuzzleGenerator(new SinusStyle());
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $outDir = sys_get_temp_dir() . '/image-puzzle-sinus-' . uniqid();
    $result = $puzzle->export($outDir, new ExportOptions());
    expect($result->getFiles())->toHaveCount(5);
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('LineStyle integration with PuzzleGenerator and export', function (): void {
    $generator = new PuzzleGenerator(new LineStyle());
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $outDir = sys_get_temp_dir() . '/image-puzzle-line-' . uniqid();
    $result = $puzzle->export($outDir, new ExportOptions());
    expect($result->getFiles())->toHaveCount(5);
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});
