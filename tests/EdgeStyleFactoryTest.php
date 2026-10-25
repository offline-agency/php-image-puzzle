<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\ArcStyle;
use ImagePuzzle\EdgeStyle\CompositeEdgeStyle;
use ImagePuzzle\EdgeStyle\EdgeStyleFactory;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\HalfCircleStyle;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\LineStyle;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;
use ImagePuzzle\EdgeStyle\SinusStyle;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Tests\Support\Fixtures;

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
    $image = Fixtures::canvas(20, 20);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new \ImagePuzzle\PieceContext(0, 0, 0, 0, 20, 20, 100, 100, 4, 4);
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
    $image = Fixtures::canvas(30, 30);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new \ImagePuzzle\PieceContext(0, 0, 0, 0, 30, 30, 100, 100, 4, 4);
    $result = $style->apply($image, $context);
    expect($result)->toBeInstanceOf(\GdImage::class);
});

test('fromArray rejects a string radius instead of casting it', function (): void {
    expect(fn () => EdgeStyleFactory::fromArray([
        'edge' => [
            'type' => 'rounded_corners',
            'options' => ['radius' => '3'],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'edge.options.radius must be an integer');
});

test('fromArray rejects malformed or unsupported options and keys', function (array $edge, string $message): void {
    expect(fn () => EdgeStyleFactory::fromArray(['edge' => $edge]))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'non-array options' => [['type' => 'jigsaw', 'options' => 'not-array'], 'must be an object'],
    'options on jigsaw' => [['type' => 'jigsaw', 'options' => ['radius' => 2]], 'not supported for edge type'],
    'unknown option' => [['type' => 'rounded_corners', 'options' => ['size' => 2]], 'unsupported keys: size'],
    'unknown key' => [['type' => 'line', 'colour' => 'red'], 'unsupported keys: colour'],
    'sides on non composite' => [['type' => 'line', 'top' => ['type' => 'line']], 'unsupported keys: top'],
    'side options' => [['type' => 'composite', 'top' => ['type' => 'line', 'options' => []]], 'unsupported keys: options'],
]);

test('normalize returns a reproducible representation', function (): void {
    expect(EdgeStyleFactory::normalize(['type' => 'rounded_corners']))->toBe(['type' => 'rounded_corners', 'options' => ['radius' => 4]]);
    expect(EdgeStyleFactory::normalize(['type' => 'composite', 'left' => ['type' => 'sinus'], 'right' => ['type' => 'sinus']]))
        ->toBe(['type' => 'composite', 'top' => ['type' => 'no_edge'], 'right' => ['type' => 'sinus'], 'bottom' => ['type' => 'no_edge'], 'left' => ['type' => 'sinus']]);
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

test('fromArray composite returns CompositeEdgeStyle with top right bottom left', function (): void {
    $config = [
        'edge' => [
            'type' => 'composite',
            'top' => ['type' => 'line'],
            'right' => ['type' => 'jigsaw'],
            'bottom' => ['type' => 'line'],
            'left' => ['type' => 'jigsaw'],
        ],
    ];
    $style = EdgeStyleFactory::fromArray($config);
    expect($style)->toBeInstanceOf(CompositeEdgeStyle::class)
        ->and($style)->toBeInstanceOf(EdgeStyleInterface::class);
    $image = Fixtures::canvas(16, 16);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    $context = new \ImagePuzzle\PieceContext(0, 0, 0, 0, 16, 16, 100, 100, 2, 2);
    $result = $style->apply($image, $context);
    expect($result)->toBeInstanceOf(\GdImage::class);
    expect(imagesx($result))->toBe(16);
    expect(imagesy($result))->toBe(16);
});

test('fromArray composite with missing side uses no_edge default', function (): void {
    $config = [
        'edge' => [
            'type' => 'composite',
            'left' => ['type' => 'sinus'],
            'right' => ['type' => 'sinus'],
        ],
    ];
    $style = EdgeStyleFactory::fromArray($config);
    expect($style)->toBeInstanceOf(CompositeEdgeStyle::class);
});

test('fromArray composite rejects contradictory and raster-only sides', function (): void {
    expect(fn () => EdgeStyleFactory::fromArray(['edge' => ['type' => 'composite', 'top' => ['type' => 'rounded_corners', 'options' => ['radius' => 2]]]]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => EdgeStyleFactory::fromArray(['edge' => ['type' => 'composite', 'top' => ['type' => 'jigsaw']]]))
        ->toThrow(InvalidArgumentException::class, 'same profile');
});

test('fromArray composite nested composite throws', function (): void {
    expect(fn () => EdgeStyleFactory::fromArray([
        'edge' => [
            'type' => 'composite',
            'top' => ['type' => 'composite', 'top' => ['type' => 'line']],
            'right' => ['type' => 'line'],
            'bottom' => ['type' => 'line'],
            'left' => ['type' => 'line'],
        ],
    ]))->toThrow(InvalidArgumentException::class, 'Nested composite');
});
