<?php

declare(strict_types=1);

use ImagePuzzle\Exporter;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\Piece;
use ImagePuzzle\PuzzleGenerator;

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

test('getPiece with negative row throws', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    expect(fn () => $puzzle->getPiece(-1, 0))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'Invalid piece index');
});

test('getPiece with negative col throws', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    expect(fn () => $puzzle->getPiece(0, -1))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'Invalid piece index');
});

test('getPiece with col out of range throws', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    expect(fn () => $puzzle->getPiece(0, 2))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'Invalid piece index');
});

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
