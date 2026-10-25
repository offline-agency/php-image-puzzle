<?php

declare(strict_types=1);

use ImagePuzzle\ExportOptions;

test('resolvePieceFilename', function (): void {
    $options = new ExportOptions();
    expect($options->resolvePieceFilename(0, 0))->toBe('piece_0_0.png');
    expect($options->resolvePieceFilename(2, 3))->toBe('piece_2_3.png');
});

test('resolvePieceFilename with JPEG format', function (): void {
    $options = new ExportOptions(format: ExportOptions::FORMAT_JPEG);
    expect($options->resolvePieceFilename(0, 0))->toBe('piece_0_0.jpg');
});

test('default getters', function (): void {
    $options = new ExportOptions();
    expect($options->getFormat())->toBe(ExportOptions::FORMAT_PNG);
    expect($options->getQuality())->toBe(9);
    expect($options->getFilenamePattern())->toBe('piece_{row}_{col}.png');
    expect($options->getOverwrite())->toBeTrue();
    expect($options->getManifestFilename())->toBe('manifest.json');
});

test('custom constructor values', function (): void {
    $options = new ExportOptions(
        format: ExportOptions::FORMAT_JPEG,
        quality: 85,
        filenamePattern: 'tile_{row}x{col}.png',
        overwrite: false,
        manifestFilename: 'puzzle.json',
    );
    expect($options->getFormat())->toBe(ExportOptions::FORMAT_JPEG);
    expect($options->getQuality())->toBe(85);
    expect($options->getFilenamePattern())->toBe('tile_{row}x{col}.png');
    expect($options->getOverwrite())->toBeFalse();
    expect($options->getManifestFilename())->toBe('puzzle.json');
});

test('resolvePieceFilename with custom pattern', function (): void {
    $options = new ExportOptions(filenamePattern: 'tile_{row}x{col}.png');
    expect($options->resolvePieceFilename(1, 2))->toBe('tile_1x2.png');
});

test('resolvePieceFilename replaces .png with .jpg when format is JPEG', function (): void {
    $options = new ExportOptions(
        format: ExportOptions::FORMAT_JPEG,
        filenamePattern: 'piece_{row}_{col}.png',
    );
    expect($options->resolvePieceFilename(0, 0))->toBe('piece_0_0.jpg');
});

test('resolvePieceFilename does not change pattern when already .jpg', function (): void {
    $options = new ExportOptions(
        format: ExportOptions::FORMAT_JPEG,
        filenamePattern: 'piece_{row}_{col}.jpg',
    );
    expect($options->resolvePieceFilename(1, 2))->toBe('piece_1_2.jpg');
});

test('resolvePieceFilename with no extension in pattern and format JPEG', function (): void {
    $options = new ExportOptions(
        format: ExportOptions::FORMAT_JPEG,
        filenamePattern: 'tile_{row}_{col}',
    );
    expect($options->resolvePieceFilename(0, 0))->toBe('tile_0_0');
});

test('constructor throws when filenamePattern contains path separator', function (): void {
    expect(fn () => new ExportOptions(filenamePattern: 'sub/piece_{row}_{col}.png'))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'must not contain path separators');
    expect(fn () => new ExportOptions(filenamePattern: 'sub\\piece_{row}_{col}.png'))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'must not contain path separators');
});

test('constructor throws when manifestFilename contains path separator', function (): void {
    expect(fn () => new ExportOptions(manifestFilename: 'sub/manifest.json'))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'must not contain path separators');
});

test('constructor throws when filenamePattern is empty or dot', function (): void {
    expect(fn () => new ExportOptions(filenamePattern: ''))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class);
    expect(fn () => new ExportOptions(filenamePattern: '.'))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class);
    expect(fn () => new ExportOptions(filenamePattern: '..'))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class);
});

test('constructor throws when manifestFilename is empty or dot', function (): void {
    expect(fn () => new ExportOptions(manifestFilename: ''))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class);
    expect(fn () => new ExportOptions(manifestFilename: '..'))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class);
});

test('withFormat returns new instance with format changed', function (): void {
    $options = new ExportOptions();
    $modified = $options->withFormat(ExportOptions::FORMAT_JPEG);
    expect($modified->getFormat())->toBe(ExportOptions::FORMAT_JPEG);
    expect($options->getFormat())->toBe(ExportOptions::FORMAT_PNG);
});

test('withQuality returns new instance with quality changed', function (): void {
    // PNG quality is a compression level 0..9; 85 is only valid for JPEG/WebP.
    expect(fn () => (new ExportOptions())->withQuality(85))->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class);
    $options = new ExportOptions();
    $modified = $options->withQuality(5);
    expect($modified->getQuality())->toBe(5);
    expect($options->getQuality())->toBe(9);
});

test('quality boundaries are format specific', function (): void {
    $options0 = new ExportOptions(quality: 0);
    $options100 = new ExportOptions(format: ExportOptions::FORMAT_JPEG, quality: 100);
    expect(fn () => new ExportOptions(quality: 10))->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class);
    expect(fn () => new ExportOptions(format: ExportOptions::FORMAT_JPEG, quality: 101))->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class);
    expect($options0->getQuality())->toBe(0);
    expect($options100->getQuality())->toBe(100);
});

test('withFilenamePattern returns new instance with pattern changed', function (): void {
    $options = new ExportOptions();
    $modified = $options->withFilenamePattern('tile_{row}x{col}.png');
    expect($modified->getFilenamePattern())->toBe('tile_{row}x{col}.png');
    expect($modified->resolvePieceFilename(1, 2))->toBe('tile_1x2.png');
});

test('withOverwrite returns new instance with overwrite changed', function (): void {
    $options = new ExportOptions();
    $modified = $options->withOverwrite(false);
    expect($modified->getOverwrite())->toBeFalse();
    expect($options->getOverwrite())->toBeTrue();
});

test('withManifestFilename returns new instance with manifest filename changed', function (): void {
    $options = new ExportOptions();
    $modified = $options->withManifestFilename('puzzle.json');
    expect($modified->getManifestFilename())->toBe('puzzle.json');
});

test('withOnPieceBeforeSave returns new instance with callback set', function (): void {
    $options = new ExportOptions();
    $callback = function (): void {};
    $modified = $options->withOnPieceBeforeSave($callback);
    expect($modified->getOnPieceBeforeSave())->toBe($callback);
    expect($options->getOnPieceBeforeSave())->toBeNull();
});

/**
 * @param array<string, mixed> $m
 * @return array<string, mixed>
 */
function identityManifest(array $m): array
{
    return $m;
}

test('withOnPieceAfterSave and withOnManifestBuild return new instance with callback set', function (): void {
    $options = new ExportOptions();
    $afterSave = function (): void {};
    $manifestBuild = identityManifest(...);
    $modified = $options->withOnPieceAfterSave($afterSave)->withOnManifestBuild($manifestBuild);
    expect($modified->getOnPieceAfterSave())->toBe($afterSave);
    expect($modified->getOnManifestBuild())->toBe($manifestBuild);
    expect($options->getOnPieceAfterSave())->toBeNull();
    expect($options->getOnManifestBuild())->toBeNull();
});

test('fluent chain', function (): void {
    $options = (new ExportOptions())
        ->withFormat(ExportOptions::FORMAT_JPEG)
        ->withQuality(85);
    expect($options->getFormat())->toBe(ExportOptions::FORMAT_JPEG);
    expect($options->getQuality())->toBe(85);
});

test('resolvePieceFilename with index rows cols placeholders when grid context provided', function (): void {
    $options = new ExportOptions(filenamePattern: 'p_{index}_of_{rows}x{cols}.png');
    expect($options->resolvePieceFilename(0, 0, 4, 6))->toBe('p_0_of_4x6.png');
    expect($options->resolvePieceFilename(2, 3, 4, 6))->toBe('p_15_of_4x6.png');
    expect($options->resolvePieceFilename(1, 1, 2, 2))->toBe('p_3_of_2x2.png');
});

test('resolvePieceFilename leaves index rows cols placeholders when grid context not provided', function (): void {
    $options = new ExportOptions(filenamePattern: 'p_{index}_r{rows}c{cols}.png');
    expect($options->resolvePieceFilename(2, 3))->toBe('p_{index}_r{rows}c{cols}.png');
});

test('resolvePieceFilename with mixed placeholders and grid context', function (): void {
    $options = new ExportOptions(filenamePattern: 'piece_{row}_{col}_{index}.png');
    expect($options->resolvePieceFilename(1, 2, 3, 4))->toBe('piece_1_2_6.png');
});

test('resolvePieceFilename with pattern without extension and format PNG leaves as-is', function (): void {
    $options = new ExportOptions(filenamePattern: 'tile_{row}_{col}');
    expect($options->resolvePieceFilename(0, 1))->toBe('tile_0_1');
});

test('resolvePieceFilename with only row placeholder', function (): void {
    $options = new ExportOptions(filenamePattern: 'row_{row}.png');
    expect($options->resolvePieceFilename(2, 3))->toBe('row_2.png');
});

test('resolvePieceFilename with only col placeholder', function (): void {
    $options = new ExportOptions(filenamePattern: 'col_{col}.png');
    expect($options->resolvePieceFilename(1, 4))->toBe('col_4.png');
});
