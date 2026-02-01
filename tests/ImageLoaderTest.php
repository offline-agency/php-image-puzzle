<?php

declare(strict_types=1);

use ImagePuzzle\Exception\GdNotAvailableException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\UnsupportedImageTypeException;
use ImagePuzzle\ImageLoader;

$skipNoGd = !extension_loaded('gd');

test('loadFromFile loads valid PNG', function (): void {
    $loader = new ImageLoader();
    $image = $loader->loadFromFile(__DIR__ . '/fixtures/sample.png');
    expect($image)->toBeInstanceOf(GdImage::class);
    $w = imagesx($image);
    $h = imagesy($image);
    expect($w)->toBeGreaterThan(0);
    expect($h)->toBeGreaterThan(0);
    $info = getimagesize(__DIR__ . '/fixtures/sample.png');
    expect($info)->not->toBeFalse();
    expect($w)->toBe($info[0]);
    expect($h)->toBe($info[1]);
})->skip($skipNoGd, 'ext-gd required');

test('loadFromFile loads valid JPEG', function (): void {
    $loader = new ImageLoader();
    $image = $loader->loadFromFile(__DIR__ . '/fixtures/sample.jpg');
    expect($image)->toBeInstanceOf(GdImage::class);
})->skip($skipNoGd, 'ext-gd required');

test('loadFromFile loads valid GIF', function (): void {
    $tmp = sys_get_temp_dir() . '/image-puzzle-loader-test-' . uniqid() . '.gif';
    $im = imagecreate(2, 2);
    expect($im)->not->toBeFalse();
    imagegif($im, $tmp);
    imagedestroy($im);
    try {
        $loader = new ImageLoader();
        $image = $loader->loadFromFile($tmp);
        expect($image)->toBeInstanceOf(GdImage::class);
        expect(imagesx($image))->toBe(2);
        expect(imagesy($image))->toBe(2);
    } finally {
        @unlink($tmp);
    }
})->skip($skipNoGd, 'ext-gd required');

test('loadFromFile with empty path throws', function (): void {
    $loader = new ImageLoader();
    expect(fn () => $loader->loadFromFile(''))
        ->toThrow(InvalidArgumentException::class, 'Image path cannot be empty');
})->skip($skipNoGd, 'ext-gd required');

test('loadFromFile with unreadable path throws', function (): void {
    $loader = new ImageLoader();
    expect(fn () => $loader->loadFromFile(__DIR__ . '/fixtures/nonexistent.png'))
        ->toThrow(InvalidArgumentException::class, 'not readable');
})->skip($skipNoGd, 'ext-gd required');

test('loadFromFile with unsupported type throws', function (): void {
    $tmp = sys_get_temp_dir() . '/image-puzzle-loader-test-' . uniqid() . '.bmp';
    file_put_contents($tmp, 'invalid');
    try {
        $loader = new ImageLoader();
        expect(fn () => $loader->loadFromFile($tmp))
            ->toThrow(UnsupportedImageTypeException::class);
    } finally {
        @unlink($tmp);
    }
})->skip($skipNoGd, 'ext-gd required');

test('loadFromFile with invalid PNG content throws', function (): void {
    $tmp = sys_get_temp_dir() . '/image-puzzle-loader-test-' . uniqid() . '.png';
    file_put_contents($tmp, 'not a valid png');
    try {
        $loader = new ImageLoader();
        expect(fn () => $loader->loadFromFile($tmp))
            ->toThrow(UnsupportedImageTypeException::class);
    } finally {
        @unlink($tmp);
    }
})->skip($skipNoGd, 'ext-gd required');

test('constructor throws GdNotAvailableException when gd not loaded', function (): void {
    expect(fn () => new ImageLoader())
        ->toThrow(GdNotAvailableException::class, 'GD');
})->skip(extension_loaded('gd'), 'Only run when ext-gd is not loaded');

test('loadFromFile with directory path throws', function (): void {
    $loader = new ImageLoader();
    expect(fn () => $loader->loadFromFile(__DIR__ . '/fixtures'))
        ->toThrow(UnsupportedImageTypeException::class);
})->skip($skipNoGd, 'ext-gd required');
