<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\EdgeStyle\ResolverEdgeStyle;
use ImagePuzzle\PieceContext;

test('ResolverEdgeStyle implements EdgeStyleInterface', function (): void {
    $resolver = fn (PieceContext $ctx): EdgeStyleInterface => new NoEdgeStyle();
    $style = new ResolverEdgeStyle($resolver);
    expect($style)->toBeInstanceOf(EdgeStyleInterface::class);
});

test('apply delegates to resolver-returned style', function (): void {
    $resolver = fn (PieceContext $ctx): EdgeStyleInterface => new NoEdgeStyle();
    $style = new ResolverEdgeStyle($resolver);
    $width = 10;
    $height = 8;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100, 2, 2);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});

test('resolver returning different style by row applies correct style', function (): void {
    $resolver = fn (PieceContext $ctx): EdgeStyleInterface =>
        $ctx->getRow() === 0 ? new NoEdgeStyle() : new JigsawStyle();
    $style = new ResolverEdgeStyle($resolver);
    $width = 16;
    $height = 12;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    imagealphablending($image, false);
    $opaque = imagecolorallocatealpha($image, 100, 100, 100, 0);
    if ($opaque !== false) {
        imagefill($image, 0, 0, $opaque);
    }
    imagealphablending($image, true);
    $contextRow0 = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100, 2, 2);
    $resultRow0 = $style->apply(copyImage($image, $width, $height), $contextRow0);
    $contextRow1 = new PieceContext(1, 0, 0, 12, $width, $height, 100, 100, 2, 2);
    $resultRow1 = $style->apply(copyImage($image, $width, $height), $contextRow1);
    $alphaAt = static function (GdImage $img, int $x, int $y): int {
        $c = imagecolorat($img, $x, $y);
        return ($c >> 24) & 0x7F;
    };
    imagesavealpha($resultRow0, true);
    imagesavealpha($resultRow1, true);
    expect($alphaAt($resultRow0, (int) floor($width / 2), 0))->toBe(0);
    expect($alphaAt($resultRow1, (int) floor($width / 2), 0))->toBe(127);
});

function copyImage(GdImage $source, int $width, int $height): GdImage
{
    $copy = imagecreatetruecolor($width, $height);
    if ($copy === false) {
        throw new \RuntimeException('Failed to create image');
    }
    imagesavealpha($copy, true);
    imagecopy($copy, $source, 0, 0, 0, 0, $width, $height);
    return $copy;
}
