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

test('apply when copyImage fails (0x0 context) continues without throwing', function (): void {
    $style = new CompositeEdgeStyle(
        new LineStyle(),
        new LineStyle(),
        new LineStyle(),
        new LineStyle(),
    );
    $image = imagecreatetruecolor(1, 1);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new PieceContext(0, 0, 0, 0, 0, 0, 1, 1, 1, 1);
    try {
        $result = $style->apply($image, $context);
        expect($result)->toBe($image);
        expect(imagesx($result))->toBe(1);
        expect(imagesy($result))->toBe(1);
    } catch (\ValueError $e) {
        expect($e->getMessage())->toContain('must be greater than 0');
    }
});

test('apply when sub-style returns different image destroys styled image', function (): void {
    $styleThatReturnsNewImage = new class implements EdgeStyleInterface {
        public function apply(\GdImage $piece, \ImagePuzzle\PieceContext $context): \GdImage
        {
            $w = imagesx($piece);
            $h = imagesy($piece);
            $new = imagecreatetruecolor($w, $h);
            if ($new === false) {
                return $piece;
            }
            imagesavealpha($new, true);
            imagealphablending($new, false);
            imagecopy($new, $piece, 0, 0, 0, 0, $w, $h);
            imagealphablending($new, true);
            return $new;
        }
    };
    $style = new CompositeEdgeStyle(
        $styleThatReturnsNewImage,
        new LineStyle(),
        new LineStyle(),
        new LineStyle(),
    );
    $width = 8;
    $height = 8;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 32, 32, 2, 2);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});
