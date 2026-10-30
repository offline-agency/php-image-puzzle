<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\SplitStrategy\DefaultSplitStrategy;
use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;

/**
 * Validated rectangular cell grid over a source image.
 */
final readonly class Grid
{
    /**
     * @param list<positive-int> $colWidths
     * @param list<positive-int> $rowHeights
     * @param list<int> $xOffsets length cols + 1
     * @param list<int> $yOffsets length rows + 1
     */
    private function __construct(
        public int $rows,
        public int $cols,
        public int $width,
        public int $height,
        public array $colWidths,
        public array $rowHeights,
        public array $xOffsets,
        public array $yOffsets,
    ) {
    }

    public static function create(int $width, int $height, int $rows, int $cols, ?DimensionSplitStrategy $strategy = null): self
    {
        self::assertGrid($width, $height, $rows, $cols);
        $strategy ??= new DefaultSplitStrategy();
        $colWidths = self::validatedSplit($strategy->split($width, $cols), $width, $cols, 'width');
        $rowHeights = self::validatedSplit($strategy->split($height, $rows), $height, $rows, 'height');

        return new self($rows, $cols, $width, $height, $colWidths, $rowHeights, self::offsets($colWidths), self::offsets($rowHeights));
    }

    public static function assertGrid(int $width, int $height, int $rows, int $cols): void
    {
        if ($rows < 1) {
            throw new InvalidArgumentException('Rows must be at least 1.');
        }
        if ($cols < 1) {
            throw new InvalidArgumentException('Cols must be at least 1.');
        }
        if ($rows > $height) {
            throw new InvalidArgumentException("Cannot split {$height} pixel rows into {$rows} rows: every cell needs at least one pixel.");
        }
        if ($cols > $width) {
            throw new InvalidArgumentException("Cannot split {$width} pixel columns into {$cols} columns: every cell needs at least one pixel.");
        }
    }

    public function cell(int $row, int $col): Bounds
    {
        $this->assertIndex($row, $col);

        return new Bounds($this->xOffsets[$col], $this->yOffsets[$row], $this->colWidths[$col], $this->rowHeights[$row]);
    }

    public function assertIndex(int $row, int $col): void
    {
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols) {
            throw new InvalidArgumentException("Invalid piece index: row={$row}, col={$col} (grid is {$this->rows}x{$this->cols})");
        }
    }

    /**
     * @param array<mixed> $split
     * @return list<positive-int>
     */
    private static function validatedSplit(array $split, int $total, int $count, string $dimension): array
    {
        if (!array_is_list($split)) {
            throw new InvalidArgumentException("Split strategy for {$dimension} must return a list.");
        }
        if (count($split) !== $count) {
            throw new InvalidArgumentException('Split strategy returned ' . count($split) . " segments, expected {$count}.");
        }
        $sum = 0;
        $validated = [];
        foreach ($split as $i => $segment) {
            if (!is_int($segment)) {
                throw new InvalidArgumentException("Split strategy segment #{$i} for {$dimension} must be an integer.");
            }
            if ($segment < 1) {
                throw new InvalidArgumentException("Split strategy segment #{$i} for {$dimension} must be at least 1 pixel, got {$segment}.");
            }
            $sum += $segment;
            $validated[] = $segment;
        }
        if ($sum !== $total) {
            throw new InvalidArgumentException("Split strategy segments sum to {$sum}, expected {$total}.");
        }

        return $validated;
    }

    /**
     * @param list<int> $sizes
     * @return list<int>
     */
    private static function offsets(array $sizes): array
    {
        $offsets = [0];
        $acc = 0;
        foreach ($sizes as $size) {
            $acc += $size;
            $offsets[] = $acc;
        }

        return $offsets;
    }
}
