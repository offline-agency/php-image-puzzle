<?php

declare(strict_types=1);

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Geometry\Bounds;
use ImagePuzzle\Tests\Support\Fixtures;

test('round-trips a versioned configuration through JSON', function (): void {
    $config = new BuildConfiguration(GameKind::Jigsaw, 'custom', ['rows' => 2, 'cols' => 3, 'topology' => 'hex'], 42, new Bounds(10, 5, 120, 90));
    $doc = $config->toArray();

    expect($doc)->toBe([
        'format' => 'image-puzzle/build-config',
        'version' => 1,
        'kind' => 'jigsaw',
        'preset' => 'custom',
        'custom' => ['rows' => 2, 'cols' => 3, 'topology' => 'hex'],
        'seed' => 42,
        'crop' => ['x' => 10, 'y' => 5, 'width' => 120, 'height' => 90],
    ]);
    expect(BuildConfiguration::fromJson($config->toJson())->toArray())->toBe($doc);
});

test('omits empty custom parameters and a missing crop, and defaults them when reading', function (): void {
    $config = BuildConfiguration::fromArray(['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'easy', 'seed' => 3]);

    expect($config->custom)->toBe([])
        ->and($config->crop)->toBeNull()
        ->and($config->toArray())->toBe(['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'easy', 'seed' => 3]);
});

test('rejects invalid configuration documents with a precise message', function (mixed $doc, string $message): void {
    expect(fn () => BuildConfiguration::fromArray($doc))->toThrow(InvalidDefinitionException::class, $message);
})->with([
    'not an object' => ['nope', 'config must be an object'],
    'wrong format' => [['format' => 'x', 'version' => 1, 'kind' => 'swap', 'preset' => 'easy', 'seed' => 0], 'config.format'],
    'future version' => [['format' => 'image-puzzle/build-config', 'version' => 2, 'kind' => 'swap', 'preset' => 'easy', 'seed' => 0], 'version 2'],
    'unknown key' => [['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'easy', 'seed' => 0, 'path' => '/etc'], 'path'],
    'unknown kind' => [['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'chess', 'preset' => 'easy', 'seed' => 0], 'config.kind'],
    'unknown preset' => [['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'insane', 'seed' => 0], 'config.preset'],
    'negative seed' => [['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'easy', 'seed' => -1], 'config.seed'],
    'seed above 32 bits' => [['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'easy', 'seed' => 4294967296], 'config.seed'],
    'jigsaw seed above 31 bits' => [['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'jigsaw', 'preset' => 'easy', 'seed' => 2147483648], 'between 0 and 2147483647 for jigsaw'],
    'custom without the custom preset' => [['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'easy', 'custom' => ['rows' => 2], 'seed' => 0], 'requires the "custom" preset'],
    'custom key of another mode' => [['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'custom', 'custom' => ['count' => 2], 'seed' => 0], 'count'],
    'empty crop' => [['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'easy', 'seed' => 0, 'crop' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 10]], 'config.crop.width'],
    'fractional crop' => [['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'easy', 'seed' => 0, 'crop' => ['x' => 0.5, 'y' => 0, 'width' => 10, 'height' => 10]], 'config.crop.x'],
]);

test('builds from a configuration, cropping the upright source first', function (): void {
    $dir = Fixtures::tempDir('ip-config-');
    try {
        imagepng(Fixtures::coordinateImage(160, 120, false), $dir . '/photo.png');
        $config = new BuildConfiguration(GameKind::Swap, 'easy', [], 4, new Bounds(20, 10, 90, 60));
        $result = (new DefinitionBuilder())->buildConfigured($dir . '/photo.png', $dir . '/out', $config);

        $size = getimagesize($dir . '/out/source.png');
        expect($result->definition->image)->toBe('source.png')
            ->and($result->definition->sourceWidth)->toBe(90)
            ->and($result->definition->sourceHeight)->toBe(60)
            ->and($size !== false ? [$size[0], $size[1]] : null)->toBe([90, 60]);

        // The cropped pixels are the requested window of the original.
        $original = imagecreatefrompng($dir . '/photo.png');
        $cropped = imagecreatefrompng($dir . '/out/source.png');
        expect($original)->not->toBeFalse()->and($cropped)->not->toBeFalse();
        if ($original !== false && $cropped !== false) {
            expect(imagecolorat($cropped, 0, 0))->toBe(imagecolorat($original, 20, 10))
                ->and(imagecolorat($cropped, 89, 59))->toBe(imagecolorat($original, 109, 69));
        }

        // Same configuration and source: same definition.
        $again = (new DefinitionBuilder())->buildConfigured($dir . '/photo.png', $dir . '/again', BuildConfiguration::fromJson($config->toJson()));
        expect($again->definition->id)->toBe($result->definition->id);
    } finally {
        Fixtures::removeDir($dir);
    }
});

test('rejects a crop outside the image and leaves no output behind', function (): void {
    $dir = Fixtures::tempDir('ip-config-');
    try {
        imagepng(Fixtures::coordinateImage(160, 120, false), $dir . '/photo.png');
        $config = new BuildConfiguration(GameKind::Swap, 'easy', [], 0, new Bounds(100, 0, 61, 120));

        expect(fn () => (new DefinitionBuilder())->buildConfigured($dir . '/photo.png', $dir . '/out', $config))
            ->toThrow(InvalidArgumentException::class, 'outside the 160x120 image');
        expect(file_exists($dir . '/out'))->toBeFalse();
    } finally {
        Fixtures::removeDir($dir);
    }
});

test('the largest jigsaw seed the configuration accepts builds', function (): void {
    $dir = Fixtures::tempDir('ip-config-');
    try {
        imagepng(Fixtures::coordinateImage(160, 120, false), $dir . '/photo.png');
        $config = new BuildConfiguration(GameKind::Jigsaw, 'custom', ['rows' => 2, 'cols' => 2], 2147483647);
        expect((new DefinitionBuilder())->buildConfigured($dir . '/photo.png', $dir . '/out', $config)->definition->seed)->toBe(2147483647);
    } finally {
        Fixtures::removeDir($dir);
    }
});
