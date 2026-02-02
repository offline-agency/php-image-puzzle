<?php

declare(strict_types=1);

use ImagePuzzle\Exporter;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\Piece;
use ImagePuzzle\PuzzleGenerator;

test('getRows getCols getWidth getHeight return puzzle dimensions', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 3);
    expect($puzzle->getRows())->toBe(2);
    expect($puzzle->getCols())->toBe(3);
    expect($puzzle->getWidth())->toBe(32);
    expect($puzzle->getHeight())->toBe(32);
});

test('getSourceBasename returns basename when puzzle created from file', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    expect($puzzle->getSourceBasename())->toBe('sample.png');
});

test('getSourceBasename returns null when puzzle created from GdImage', function (): void {
    $image = imagecreatefrompng(__DIR__ . '/fixtures/sample.png');
    expect($image)->not->toBeFalse();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromGd($image, 2, 2);
    expect($puzzle->getSourceBasename())->toBeNull();
});

test('getPieces yields pieces in row-major order', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $order = [];
    foreach ($puzzle->getPieces() as $piece) {
        $order[] = [$piece->getRow(), $piece->getCol()];
    }
    expect($order)->toBe([[0, 0], [0, 1], [1, 0], [1, 1]]);
});

test('getPieces yields Piece instances', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $piece = null;
    foreach ($puzzle->getPieces() as $p) {
        $piece = $p;
        break;
    }
    expect($piece)->toBeInstanceOf(Piece::class);
});

test('getPiece with invalid indices throws', function (int $row, int $col): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    expect(fn () => $puzzle->getPiece($row, $col))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'Invalid piece index');
})->with([
    'negative row' => [-1, 0],
    'negative col' => [0, -1],
    'row out of range' => [2, 0],
    'col out of range' => [0, 2],
]);

test('getPiece error message includes grid dimensions', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 3);
    try {
        $puzzle->getPiece(2, 0);
        expect(false)->toBeTrue('Expected exception');
    } catch (\ImagePuzzle\Exception\InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('2x3');
    }
});

test('export with default options uses default ExportOptions', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $outDir = sys_get_temp_dir() . '/image-puzzle-default-opts-' . uniqid();
    $result = $puzzle->export($outDir);
    expect($result->getOutputDirectory())->toBe($outDir);
    expect($result->getManifestPath())->toEndWith('manifest.json');
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export with baseDirectory null succeeds', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $outDir = sys_get_temp_dir() . '/image-puzzle-null-base-' . uniqid();
    $result = $puzzle->export($outDir, new ExportOptions(), null);
    expect($result->getOutputDirectory())->toBe($outDir);
    expect($result->getFiles())->toHaveCount(2);
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('puzzle from generator with injected exporter uses that exporter on export', function (): void {
    $exporter = new Exporter();
    $generator = new PuzzleGenerator(null, null, $exporter);
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $outDir = sys_get_temp_dir() . '/image-puzzle-injected-' . uniqid();
    $result = $puzzle->export($outDir, new ExportOptions());
    expect($result->getFiles())->toHaveCount(2);
    expect($result->getManifestPath())->toBeFile();
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});
