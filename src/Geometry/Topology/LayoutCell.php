<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Topology;

use ImagePuzzle\Geometry\Bounds;

/**
 * One logical cell. Corners are listed clockwise in screen coordinates
 * (y grows downwards); edge i runs from corner i to corner i + 1.
 */
final readonly class LayoutCell
{
    /**
     * @param list<array{0: float, 1: float}> $corners
     * @param list<array{0: string, 1: bool}> $edges [edge key, reversed] in corner order
     * @param Bounds $logical integer logical cell bounds (the exact cell for rectangles)
     */
    public function __construct(
        public string $id,
        public int $row,
        public int $col,
        public array $corners,
        public array $edges,
        public Bounds $logical,
    ) {
    }

    public static function idFor(int $row, int $col): string
    {
        return "r{$row}c{$col}";
    }
}
