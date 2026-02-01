<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\ArcStyle;
use ImagePuzzle\EdgeStyle\EdgeStyleFactory;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\HalfCircleStyle;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\LineStyle;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;
use ImagePuzzle\EdgeStyle\SinusStyle;
use ImagePuzzle\Exception\InvalidArgumentException;

test('fromArray no_edge returns NoEdgeStyle', function (): void {
    $style = EdgeStyleFactory::fromArray(['edge' => ['type' => 'no_edge']]);
    expect($style)->toBeInstanceOf(EdgeStyleInterface::class)
        ->and($style)->toBeInstanceOf(NoEdgeStyle::class);
});

test('fromArray line returns LineStyle', function (): void {
    $style = EdgeStyleFactory::fromArray(['edge' => ['type' => 'line']]);
    expect($style)->toBeInstanceOf(LineStyle::class);
});

test('fromArray jigsaw returns JigsawStyle', function (): void {
    $style = EdgeStyleFactory::fromArray(['edge' => ['type' => 'jigsaw']]);
    expect($style)->toBeInstanceOf(JigsawStyle::class);
});

test('fromArray arc returns ArcStyle', function (): void {
    $style = EdgeStyleFactory::fromArray(['edge' => ['type' => 'arc']]);
    expect($style)->toBeInstanceOf(ArcStyle::class);
});

test('fromArray half_circle returns HalfCircleStyle', function (): void {
    $style = EdgeStyleFactory::fromArray(['edge' => ['type' => 'half_circle']]);
    expect($style)->toBeInstanceOf(HalfCircleStyle::class);
});

test('fromArray sinus returns SinusStyle', function (): void {
    $style = EdgeStyleFactory::fromArray(['edge' => ['type' => 'sinus']]);
    expect($style)->toBeInstanceOf(SinusStyle::class);
});

test('fromArray rounded_corners without options uses default radius', function (): void {
    $style = EdgeStyleFactory::fromArray(['edge' => ['type' => 'rounded_corners']]);
    expect($style)->toBeInstanceOf(RoundedCornersStyle::class);
    $image = imagecreatetruecolor(20, 20);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new \ImagePuzzle\PieceContext(0, 0, 0, 0, 20, 20, 100, 100);
    $result = $style->apply($image, $context);
    expect($result)->toBeInstanceOf(\GdImage::class);
});

test('fromArray rounded_corners with options.radius uses that value', function (): void {
    $style = EdgeStyleFactory::fromArray([
        'edge' => [
            'type' => 'rounded_corners',
            'options' => ['radius' => 6],
        ],
    ]);
    expect($style)->toBeInstanceOf(RoundedCornersStyle::class);
    $image = imagecreatetruecolor(30, 30);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new \ImagePuzzle\PieceContext(0, 0, 0, 0, 30, 30, 100, 100);
    $result = $style->apply($image, $context);
    expect($result)->toBeInstanceOf(\GdImage::class);
});

test('fromArray rounded_corners with options.radius as string casts to int', function (): void {
    $style = EdgeStyleFactory::fromArray([
        'edge' => [
            'type' => 'rounded_corners',
            'options' => ['radius' => '3'],
        ],
    ]);
    expect($style)->toBeInstanceOf(RoundedCornersStyle::class);
});

test('fromArray missing edge throws', function (): void {
    expect(fn () => EdgeStyleFactory::fromArray([]))
        ->toThrow(InvalidArgumentException::class, 'contain an "edge" object');
});

test('fromArray edge not array throws', function (): void {
    expect(fn () => EdgeStyleFactory::fromArray(['edge' => 'jigsaw']))
        ->toThrow(InvalidArgumentException::class, 'contain an "edge" object');
});

test('fromArray missing type throws', function (): void {
    expect(fn () => EdgeStyleFactory::fromArray(['edge' => []]))
        ->toThrow(InvalidArgumentException::class, 'contain a "type" string');
});

test('fromArray type not string throws', function (): void {
    expect(fn () => EdgeStyleFactory::fromArray(['edge' => ['type' => 123]]))
        ->toThrow(InvalidArgumentException::class, 'contain a "type" string');
});

test('fromArray unknown type throws', function (): void {
    expect(fn () => EdgeStyleFactory::fromArray(['edge' => ['type' => 'unknown']]))
        ->toThrow(InvalidArgumentException::class, 'Unknown edge type: unknown');
});

test('fromArray rounded_corners radius zero throws from RoundedCornersStyle', function (): void {
    expect(fn () => EdgeStyleFactory::fromArray([
        'edge' => [
            'type' => 'rounded_corners',
            'options' => ['radius' => 0],
        ],
    ]))->toThrow(\InvalidArgumentException::class, 'Radius must be at least 1');
});

test('fromArray rounded_corners radius negative throws from RoundedCornersStyle', function (): void {
    expect(fn () => EdgeStyleFactory::fromArray([
        'edge' => [
            'type' => 'rounded_corners',
            'options' => ['radius' => -1],
        ],
    ]))->toThrow(\InvalidArgumentException::class, 'Radius must be at least 1');
});

test('fromFile loads JSON and returns same style as fromArray', function (): void {
    $tmp = sys_get_temp_dir() . '/php-image-puzzle-edge-config-' . uniqid() . '.json';
    try {
        file_put_contents($tmp, '{"edge":{"type":"jigsaw"}}');
        $style = EdgeStyleFactory::fromFile($tmp);
        expect($style)->toBeInstanceOf(JigsawStyle::class);
        $fromArray = EdgeStyleFactory::fromArray(['edge' => ['type' => 'jigsaw']]);
        expect($style)->toBeInstanceOf(get_class($fromArray));
    } finally {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }
});

test('fromFile with rounded_corners and radius', function (): void {
    $tmp = sys_get_temp_dir() . '/php-image-puzzle-edge-config-' . uniqid() . '.json';
    try {
        file_put_contents($tmp, '{"edge":{"type":"rounded_corners","options":{"radius":5}}}');
        $style = EdgeStyleFactory::fromFile($tmp);
        expect($style)->toBeInstanceOf(RoundedCornersStyle::class);
    } finally {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }
});

test('fromFile non-existent file throws', function (): void {
    $path = __DIR__ . '/fixtures/non-existent-edge-config.json';
    expect(fn () => EdgeStyleFactory::fromFile($path))
        ->toThrow(InvalidArgumentException::class, 'not readable');
});

test('fromFile non-json extension throws', function (): void {
    $tmp = sys_get_temp_dir() . '/php-image-puzzle-edge-config-' . uniqid() . '.yaml';
    try {
        file_put_contents($tmp, 'edge:\n  type: jigsaw');
        expect(fn () => EdgeStyleFactory::fromFile($tmp))
            ->toThrow(InvalidArgumentException::class, 'Only .json');
    } finally {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }
});

test('fromFile invalid JSON throws', function (): void {
    $tmp = sys_get_temp_dir() . '/php-image-puzzle-edge-config-' . uniqid() . '.json';
    try {
        file_put_contents($tmp, '{ invalid json');
        expect(fn () => EdgeStyleFactory::fromFile($tmp))
            ->toThrow(InvalidArgumentException::class, 'Invalid JSON');
    } finally {
        if (is_file($tmp)) {
            unlink($tmp);
        }
    }
});
