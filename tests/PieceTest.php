<?php

declare(strict_types=1);

use ImagePuzzle\PuzzleGenerator;

test('piece coordinates and dimensions', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $piece = $puzzle->getPiece(0, 0);
    expect($piece->getRow())->toBe(0);
    expect($piece->getCol())->toBe(0);
    expect($piece->getX())->toBe(0);
    expect($piece->getY())->toBe(0);
    expect($piece->getWidth())->toBe(16);
    expect($piece->getHeight())->toBe(16);
});

test('getPiece 0 1 returns piece with correct getters', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $piece = $puzzle->getPiece(0, 1);
    expect($piece->getRow())->toBe(0);
    expect($piece->getCol())->toBe(1);
    expect($piece->getX())->toBe(16);
    expect($piece->getY())->toBe(0);
    expect($piece->getWidth())->toBe(16);
    expect($piece->getHeight())->toBe(16);
});

test('toGdImage returns valid image with correct dimensions', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $piece = $puzzle->getPiece(1, 1);
    $image = $piece->toGdImage();
    expect($image)->toBeInstanceOf(GdImage::class);
    expect(imagesx($image))->toBe($piece->getWidth());
    expect(imagesy($image))->toBe($piece->getHeight());
});

test('last piece in grid has remainder dimensions when source not divisible', function (): void {
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.jpg', 3, 3);
    $pieceFirst = $puzzle->getPiece(0, 0);
    $pieceLast = $puzzle->getPiece(2, 2);
    expect($pieceLast->getWidth())->toBeGreaterThanOrEqual($pieceFirst->getWidth());
    expect($pieceLast->getHeight())->toBeGreaterThanOrEqual($pieceFirst->getHeight());
    expect($pieceFirst->getWidth() + $puzzle->getPiece(0, 1)->getWidth() + $puzzle->getPiece(0, 2)->getWidth())
        ->toBe($puzzle->getWidth());
});
