<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\CompositeEdgeStyle;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\LineStyle;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;
use ImagePuzzle\EdgeStyle\SinusStyle;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Geometry\Topology\LayoutEdge;
use ImagePuzzle\GeometryOptions;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\Tests\Support\Fixtures;

test('CompositeEdgeStyle implements EdgeStyleInterface', function (): void {
    $style = new CompositeEdgeStyle(new LineStyle(), new LineStyle(), new LineStyle(), new LineStyle());
    expect($style)->toBeInstanceOf(EdgeStyleInterface::class);
});

test('opposite sides must agree because they describe the same shared boundaries', function (): void {
    expect(fn () => new CompositeEdgeStyle(new LineStyle(), new JigsawStyle(), new JigsawStyle(), new LineStyle()))
        ->toThrow(InvalidArgumentException::class, 'must use the same profile');
});

test('raster-only styles are rejected as composite sides', function (): void {
    expect(fn () => new CompositeEdgeStyle(new RoundedCornersStyle(), new LineStyle(), new RoundedCornersStyle(), new LineStyle()))
        ->toThrow(InvalidArgumentException::class, 'not supported');
});

test('composite applies whole side geometry: jigsaw horizontal and sinus vertical boundaries', function (): void {
    $style = new CompositeEdgeStyle(new JigsawStyle(), new SinusStyle(), new JigsawStyle(), new SinusStyle());
    $vertical = new LayoutEdge('v0_1', [30.0, 0.0], [30.0, 30.0], 'r0c0', 'r0c1', 'vertical', 30.0);
    $horizontal = new LayoutEdge('h1_0', [0.0, 30.0], [30.0, 30.0], 'r1c0', 'r0c0', 'horizontal', 30.0);
    expect($style->profileForBoundary($vertical)->name())->toBe('sinus');
    expect($style->profileForBoundary($horizontal)->name())->toBe('jigsaw');

    $puzzle = (new PuzzleGenerator($style, geometry: GeometryOptions::exact(7)))
        ->generateFromGd(Fixtures::coordinateImage(120, 90, false), 3, 4);
    $piece = $puzzle->getPiece(1, 1);
    // The interior piece outline deviates from its cell on whole sides, far beyond one pixel.
    $polygon = $piece->getShape()->polygon;
    $maxDeviation = 0.0;
    foreach ($polygon as [$x, $y]) {
        $dx = max($piece->getX() - $x, $x - ($piece->getX() + $piece->getWidth()), 0.0);
        $dy = max($piece->getY() - $y, $y - ($piece->getY() + $piece->getHeight()), 0.0);
        $inside = min(abs($x - $piece->getX()), abs($x - $piece->getX() - $piece->getWidth()), abs($y - $piece->getY()), abs($y - $piece->getY() - $piece->getHeight()));
        $maxDeviation = max($maxDeviation, $dx, $dy, $inside);
    }
    expect($maxDeviation)->toBeGreaterThan(4.0);
});

test('flat composite equals rectangular slicing', function (): void {
    $style = new CompositeEdgeStyle(new NoEdgeStyle(), new LineStyle(), new LineStyle(), new NoEdgeStyle());
    $puzzle = (new PuzzleGenerator($style))->generateFromGd(Fixtures::canvas(40, 40), 2, 2);
    foreach ($puzzle->getPieces() as $piece) {
        expect($piece->getRenderedBounds()->toArray())->toBe(['x' => $piece->getX(), 'y' => $piece->getY(), 'width' => 20, 'height' => 20]);
    }
});
