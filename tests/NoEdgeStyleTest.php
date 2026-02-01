<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\PieceContext;

test('NoEdgeStyle implements EdgeStyleInterface', function (): void {
    expect(new NoEdgeStyle())->toBeInstanceOf(EdgeStyleInterface::class);
});

test('apply returns same image with unchanged dimensions', function (): void {
    $style = new NoEdgeStyle();
    $width = 10;
    $height = 8;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100, 4, 4);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});

test('apply returns same image for any PieceContext', function (): void {
    $style = new NoEdgeStyle();
    $width = 12;
    $height = 10;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    $context = new PieceContext(1, 2, 24, 10, $width, $height, 48, 30, 3, 4);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});
