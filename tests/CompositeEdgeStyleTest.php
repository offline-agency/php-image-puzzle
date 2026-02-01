<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\CompositeEdgeStyle;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\LineStyle;
use ImagePuzzle\PieceContext;

test('CompositeEdgeStyle implements EdgeStyleInterface', function (): void {
    $style = new CompositeEdgeStyle(
        new LineStyle(),
        new LineStyle(),
        new LineStyle(),
        new LineStyle(),
    );
    expect($style)->toBeInstanceOf(EdgeStyleInterface::class);
});

test('apply returns same image with correct dimensions', function (): void {
    $style = new CompositeEdgeStyle(
        new LineStyle(),
        new JigsawStyle(),
        new JigsawStyle(),
        new LineStyle(),
    );
    $width = 16;
    $height = 12;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100, 2, 2);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});

test('apply modifies piece when different styles per side', function (): void {
    $style = new CompositeEdgeStyle(
        new LineStyle(),
        new JigsawStyle(),
        new JigsawStyle(),
        new LineStyle(),
    );
    $width = 20;
    $height = 16;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    imagealphablending($image, false);
    $opaque = imagecolorallocatealpha($image, 100, 100, 100, 0);
    if ($opaque !== false) {
        imagefill($image, 0, 0, $opaque);
    }
    imagealphablending($image, true);
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100, 2, 2);
    $result = $style->apply($image, $context);
    imagesavealpha($result, true);
    $alphaAt = static function (GdImage $img, int $x, int $y): int {
        $c = imagecolorat($img, $x, $y);
        return ($c >> 24) & 0x7F;
    };
    expect($alphaAt($result, (int) floor($width / 2), 0))->toBe(0);
    expect($alphaAt($result, $width - 1, 0))->toBe(127);
});
