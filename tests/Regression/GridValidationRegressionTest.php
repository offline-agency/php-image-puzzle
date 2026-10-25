<?php

declare(strict_types=1);

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\ResourceLimits;
use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * G04 / G15: zero-sized cells, invalid custom splits and unchecked requested
 * indices must fail before any GD allocation or output.
 */

final class FixedSplit implements DimensionSplitStrategy
{
    /** @param array<mixed> $result */
    public function __construct(private readonly array $result)
    {
    }

    public function split(int $totalSize, int $segmentCount): array
    {
        /** @phpstan-ignore return.type (deliberately invalid strategy output) */
        return $this->result;
    }
}

test('more rows or cols than source pixels is rejected', function (int $rows, int $cols): void {
    expect(fn () => (new PuzzleGenerator())->generateFromGd(Fixtures::canvas(3, 4), $rows, $cols))
        ->toThrow(InvalidArgumentException::class);
})->with([[5, 1], [1, 4], [2, 5]]);

test('zero and negative grids are rejected', function (int $rows, int $cols): void {
    expect(fn () => (new PuzzleGenerator())->generateFromGd(Fixtures::canvas(10, 10), $rows, $cols))
        ->toThrow(InvalidArgumentException::class);
})->with([[0, 1], [1, 0], [-1, 2], [2, -5]]);

test('invalid custom split strategy output is rejected', function (array $split): void {
    $generator = new PuzzleGenerator(splitStrategy: new FixedSplit($split));
    expect(fn () => $generator->generateFromGd(Fixtures::canvas(10, 10), 2, 2))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'zero segment' => [[10, 0]],
    'negative segment' => [[12, -2]],
    'non integer' => [[5.0, 5]],
    'string' => [['5', 5]],
    'wrong count' => [[10]],
    'wrong sum' => [[4, 4]],
    'not a list' => [[1 => 5, 2 => 5]],
]);

test('requested piece indices are validated before lookup', function (array $indices): void {
    $generator = new PuzzleGenerator();
    expect(fn () => $generator->generatePiecesFromGd(Fixtures::canvas(10, 10), 2, 2, $indices))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'row out of range' => [[[2, 0]]],
    'col out of range' => [[[0, 2]]],
    'negative' => [[[-1, 0]]],
    'malformed' => [[[0]]],
    'non int' => [[['0', 1]]],
    'duplicate' => [[[0, 0], [0, 0]]],
]);

test('non divisible dimensions put the remainder on the last cell', function (): void {
    $puzzle = (new PuzzleGenerator())->generateFromGd(Fixtures::canvas(101, 50), 3, 5);
    $widths = array_map(static fn (int $c): int => $puzzle->getPiece(0, $c)->getWidth(), range(0, 4));
    expect($widths)->toBe([20, 20, 20, 20, 21]);
    $heights = array_map(static fn (int $r): int => $puzzle->getPiece($r, 0)->getHeight(), range(0, 2));
    expect($heights)->toBe([16, 16, 18]);
});

test('piece budget is enforced before generation', function (): void {
    $limits = new ResourceLimits(maxPieces: 10);
    expect(fn () => (new PuzzleGenerator(limits: $limits))->generateFromGd(Fixtures::canvas(100, 100), 4, 4))
        ->toThrow(ResourceLimitExceededException::class);
});

test('pixel budget is enforced on supplied images', function (): void {
    $limits = new ResourceLimits(maxPixels: 99);
    expect(fn () => (new PuzzleGenerator(limits: $limits))->generateFromGd(Fixtures::canvas(10, 10), 1, 1))
        ->toThrow(ResourceLimitExceededException::class);
});

test('budget rejection leaves no output', function (): void {
    $dir = Fixtures::tempDir();
    $src = Fixtures::writePng(Fixtures::canvas(50, 50), $dir . '/src.png');
    $out = $dir . '/out';
    try {
        PuzzleGenerator::generateAndExport($src, $out, 20, 20, options: null, limits: new ResourceLimits(maxPieces: 4));
    } catch (ResourceLimitExceededException) {
    }
    expect(file_exists($out))->toBeFalse();
    Fixtures::removeDir($dir);
});
