<?php

declare(strict_types=1);

use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\SplitStrategy\DefaultSplitStrategy;
use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;

test('DefaultSplitStrategy remainder to last segment', function (): void {
    $strategy = new DefaultSplitStrategy();
    $splits = $strategy->split(100, 3);
    expect($splits)->toBe([33, 33, 34]);
    expect(array_sum($splits))->toBe(100);
});

test('DefaultSplitStrategy even division', function (): void {
    $strategy = new DefaultSplitStrategy();
    $splits = $strategy->split(50, 2);
    expect($splits)->toBe([25, 25]);
});

test('DefaultSplitStrategy single segment', function (): void {
    $strategy = new DefaultSplitStrategy();
    $splits = $strategy->split(100, 1);
    expect($splits)->toBe([100]);
});

test('DefaultSplitStrategy totalSize less than segmentCount', function (): void {
    $strategy = new DefaultSplitStrategy();
    $splits = $strategy->split(5, 10);
    expect($splits)->toHaveCount(10);
    expect(array_sum($splits))->toBe(5);
    expect($splits)->toBe([0, 0, 0, 0, 0, 0, 0, 0, 0, 5]);
});

test('DefaultSplitStrategy totalSize zero', function (): void {
    $strategy = new DefaultSplitStrategy();
    $splits = $strategy->split(0, 1);
    expect($splits)->toBe([0]);
});

test('DefaultSplitStrategy split 7 into 3 segments returns remainder on last', function (): void {
    $strategy = new DefaultSplitStrategy();
    $splits = $strategy->split(7, 3);
    expect($splits)->toBe([2, 2, 3]);
    expect(array_sum($splits))->toBe(7);
});

test('PuzzleGenerator with DefaultSplitStrategy produces same result as default', function (): void {
    $generator = new PuzzleGenerator(null, null, null, new DefaultSplitStrategy());
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $piece00 = $puzzle->getPiece(0, 0);
    expect($piece00->getWidth())->toBe(16);
    expect($piece00->getHeight())->toBe(16);
});

test('PuzzleGenerator with custom split strategy uses custom segments', function (): void {
    $customStrategy = new class implements DimensionSplitStrategy {
        public function split(int $totalSize, int $segmentCount): array
        {
            $base = (int) floor($totalSize / $segmentCount);
            $splits = array_fill(0, $segmentCount, $base);
            $splits[$segmentCount - 1] += $totalSize - $base * $segmentCount;
            return $splits;
        }
    };
    $generator = new PuzzleGenerator(null, null, null, $customStrategy);
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $piece00 = $puzzle->getPiece(0, 0);
    expect($piece00->getWidth())->toBe(16);
    expect($piece00->getHeight())->toBe(16);
});

test('PuzzleGenerator with invalid split strategy wrong count throws', function (): void {
    $badStrategy = new class implements DimensionSplitStrategy {
        public function split(int $totalSize, int $segmentCount): array
        {
            return [10, 10, 12];
        }
    };
    $generator = new PuzzleGenerator(null, null, null, $badStrategy);
    expect(fn () => $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'Split strategy returned');
});

test('PuzzleGenerator with invalid split strategy wrong sum throws', function (): void {
    $badStrategy = new class implements DimensionSplitStrategy {
        public function split(int $totalSize, int $segmentCount): array
        {
            return [1, 2];
        }
    };
    $generator = new PuzzleGenerator(null, null, null, $badStrategy);
    expect(fn () => $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'sum to');
});
