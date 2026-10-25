<?php

declare(strict_types=1);

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\Exception\UnsupportedImageTypeException;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\ResourceLimits;
use ImagePuzzle\Tests\Support\Fixtures;

/**
 * A temporary directory holding a 160x120 photo.png; removed after each test.
 *
 * @return array{0: string, 1: string} directory and source path
 */
function builderWorkspace(): array
{
    $dir = Fixtures::tempDir('ip-builder-');
    BuilderWorkspaces::$dirs[] = $dir;
    imagepng(Fixtures::coordinateImage(160, 120, false), $dir . '/photo.png');

    return [$dir, $dir . '/photo.png'];
}

final class BuilderWorkspaces
{
    /** @var list<string> */
    public static array $dirs = [];
}

afterEach(function (): void {
    foreach (BuilderWorkspaces::$dirs as $dir) {
        Fixtures::removeDir($dir);
    }
    BuilderWorkspaces::$dirs = [];
});

test('builds every grid mode with a copied source and a valid definition', function (GameKind $kind): void {
    [$dir, $source] = builderWorkspace();
    $out = $dir . '/out-' . $kind->value;
    $result = (new DefinitionBuilder())->build($source, $out, $kind, 'easy', seed: 7);

    expect($result->files)->toBe(['definition.json', 'source.png'])
        ->and(file_get_contents($out . '/source.png'))->toBe(file_get_contents($source))
        ->and($result->definition->image)->toBe('source.png')
        ->and($result->definition->sourceSha256)->toBe(hash_file('sha256', $source));
    $reloaded = GameDefinition::fromJson((string) file_get_contents($result->definitionPath()));
    expect($reloaded->id)->toBe($result->definition->id)
        ->and($reloaded->kind)->toBe($kind);
})->with([GameKind::Sliding, GameKind::Swap, GameKind::Rotation, GameKind::Strips]);

test('builds a jigsaw with exported pieces referenced from the definition', function (string $topology): void {
    [$dir, $source] = builderWorkspace();
    $out = $dir . '/jigsaw';
    $result = (new DefinitionBuilder())->build($source, $out, GameKind::Jigsaw, 'custom', ['rows' => 2, 'cols' => 3, 'topology' => $topology, 'snap' => 6], 3);

    $definition = $result->definition;
    expect($definition->rules['topology'])->toBe($topology)
        ->and($definition->rules['snap'])->toBe(6)
        ->and($result->files)->toContain('definition.json', 'source.png', 'pieces/manifest.json');
    foreach ($definition->tiles as $tile) {
        $image = $tile->attributes['image'];
        expect($image)->toBeString()->toStartWith('pieces/')
            ->and(is_file($out . '/' . (is_string($image) ? $image : '')))->toBeTrue();
    }
    expect(GameDefinition::fromJson((string) file_get_contents($result->definitionPath()))->id)->toBe($definition->id);
})->with(['rect', 'hex', 'triangle']);

test('the same inputs build the same definition', function (): void {
    [$dir, $source] = builderWorkspace();
    $builder = new DefinitionBuilder();
    $a = $builder->build($source, $dir . '/a', GameKind::Jigsaw, 'easy', seed: 9);
    $b = $builder->build($source, $dir . '/b', GameKind::Jigsaw, 'easy', seed: 9);
    expect($b->definition->id)->toBe($a->definition->id);
});

test('never writes into a non-empty directory', function (): void {
    [$dir, $source] = builderWorkspace();
    $out = $dir . '/taken';
    mkdir($out);
    file_put_contents($out . '/other.json', '{}');

    expect(fn () => (new DefinitionBuilder())->build($source, $out, GameKind::Swap))
        ->toThrow(InvalidArgumentException::class, 'must be empty');
    expect(scandir($out))->toBe(['.', '..', 'other.json']);
});

test('an existing empty directory is used and emptied again on failure', function (): void {
    [$dir, $source] = builderWorkspace();
    $out = $dir . '/empty';
    mkdir($out);
    expect(fn () => (new DefinitionBuilder())->build($source, $out, GameKind::Swap, 'custom', ['rows' => 99]))
        ->toThrow(InvalidDefinitionException::class, 'rows');
    expect(is_dir($out))->toBeTrue()
        ->and(scandir($out))->toBe(['.', '..']);
});

test('a failed build removes the directory it created', function (): void {
    [$dir, $source] = builderWorkspace();
    $out = $dir . '/new/nested';
    expect(fn () => (new DefinitionBuilder(limits: new ResourceLimits(maxPieces: 4)))->build($source, $out, GameKind::Jigsaw, 'easy'))
        ->toThrow(ResourceLimitExceededException::class);
    expect(file_exists($out))->toBeFalse();
});

test('rejects unsupported sources before creating anything', function (): void {
    [$dir, $source] = builderWorkspace();
    file_put_contents($dir . '/fake.png', 'not an image');
    $out = $dir . '/never';
    expect(fn () => (new DefinitionBuilder())->build($dir . '/fake.png', $out, GameKind::Swap))
        ->toThrow(UnsupportedImageTypeException::class);
    expect(file_exists($out))->toBeFalse();
});

test('rejects unknown custom jigsaw keys and custom keys without the custom preset', function (): void {
    [$dir, $source] = builderWorkspace();
    $builder = new DefinitionBuilder();
    expect(fn () => $builder->build($source, $dir . '/x', GameKind::Jigsaw, 'custom', ['rows' => 2, 'cols' => 2, 'colour' => 'red']))
        ->toThrow(InvalidArgumentException::class, 'colour');
    expect(fn () => $builder->build($source, $dir . '/y', GameKind::Jigsaw, 'easy', ['rows' => 2]))
        ->toThrow(InvalidArgumentException::class, 'custom');
});

test('rejects modes without rules', function (): void {
    [$dir, $source] = builderWorkspace();
    expect(fn () => (new DefinitionBuilder())->build($source, $dir . '/m', GameKind::Memory))
        ->toThrow(InvalidArgumentException::class, 'memory');
});

test('re-encodes an EXIF-rotated JPEG upright so browsers show the same pixels', function (): void {
    [$dir, $source] = builderWorkspace();
    // 160x80 JPEG tagged orientation 6 (rotate 90° clockwise to display): displayed as 80x160.
    $image = Fixtures::canvas(160, 80);
    imagefilledrectangle($image, 0, 0, 79, 79, Fixtures::color($image, 255, 0, 0));
    imagefilledrectangle($image, 80, 0, 159, 79, Fixtures::color($image, 0, 0, 255));
    ob_start();
    imagejpeg($image, null, 95);
    $jpeg = (string) ob_get_clean();
    $tiff = 'MM' . pack('n', 42) . pack('N', 8) . pack('n', 1) . pack('n', 0x0112) . pack('n', 3) . pack('N', 1) . pack('n', 6) . pack('n', 0) . pack('N', 0);
    $payload = "Exif\0\0" . $tiff;
    file_put_contents($dir . '/rotated.jpg', substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2));

    $result = (new DefinitionBuilder())->build($dir . '/rotated.jpg', $dir . '/r', GameKind::Swap, 'easy');
    expect($result->definition->image)->toBe('source.png')
        ->and(Fixtures::size($dir . '/r/source.png'))->toBe([80, 160])
        ->and($result->definition->sourceWidth)->toBe(80)
        ->and($result->definition->sourceHeight)->toBe(160)
        ->and($result->definition->sourceSha256)->toBe(hash_file('sha256', $dir . '/r/source.png'));
});
