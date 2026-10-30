<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\EdgeStyle\ResolverEdgeStyle;
use ImagePuzzle\PieceContext;
use ImagePuzzle\Tests\Support\Fixtures;

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
    $image = Fixtures::canvas($width, $height);
    expect($image)->not->toBeFalse();
    $context = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100, 2, 2);
    $result = $style->apply($image, $context);
    expect($result)->toBe($image);
    expect(imagesx($result))->toBe($width);
    expect(imagesy($result))->toBe($height);
});

test('resolver returning different raster effect by row applies correct style', function (): void {
    $resolver = fn (PieceContext $ctx): EdgeStyleInterface =>
        $ctx->getRow() === 0 ? new NoEdgeStyle() : new \ImagePuzzle\EdgeStyle\RoundedCornersStyle(3);
    $style = new ResolverEdgeStyle($resolver);
    $width = 16;
    $height = 12;
    $image = Fixtures::canvas($width, $height);
    $contextRow0 = new PieceContext(0, 0, 0, 0, $width, $height, 100, 100, 2, 2);
    $resultRow0 = $style->apply(copyImage($image, $width, $height), $contextRow0);
    $contextRow1 = new PieceContext(1, 0, 0, 12, $width, $height, 100, 100, 2, 2);
    $resultRow1 = $style->apply(copyImage($image, $width, $height), $contextRow1);
    $alphaAt = static fn (GdImage $img, int $x, int $y): int => (imagecolorat($img, $x, $y) >> 24) & 0x7F;
    expect($alphaAt($resultRow0, 0, 0))->toBe(0);
    expect($alphaAt($resultRow1, 0, 0))->toBe(127);
});

test('resolver returning shared-geometry styles is rejected', function (): void {
    $style = new ResolverEdgeStyle(fn (PieceContext $ctx): EdgeStyleInterface => new JigsawStyle());
    $context = new PieceContext(0, 0, 0, 0, 4, 4, 8, 8, 2, 2);
    expect(fn () => $style->apply(Fixtures::canvas(4, 4), $context))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'raster effects');
});

function copyImage(GdImage $source, int $width, int $height): GdImage
{
    $copy = Fixtures::canvas($width, $height);
    imagesavealpha($copy, true);
    imagecopy($copy, $source, 0, 0, 0, 0, $width, $height);
    return $copy;
}
