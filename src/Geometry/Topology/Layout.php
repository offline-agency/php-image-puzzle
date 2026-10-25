<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Topology;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Board subdivision produced by a topology: cells with straight base polygons
 * and the boundaries between them. Every internal boundary appears exactly
 * once and is referenced by its two cells in opposite directions.
 */
final readonly class Layout
{
    /** @var array<string, LayoutCell> */
    public array $cells;

    /**
     * @param string $topology topology identifier ("rect", "hex", "triangle")
     * @param list<LayoutCell> $cells
     * @param array<string, LayoutEdge> $edges keyed by edge key
     */
    public function __construct(
        public string $topology,
        public int $width,
        public int $height,
        array $cells,
        public array $edges,
    ) {
        $indexed = [];
        foreach ($cells as $cell) {
            $indexed[$cell->id] = $cell;
        }
        $this->cells = $indexed;
    }

    public function cell(string $id): LayoutCell
    {
        return $this->cells[$id] ?? throw new InvalidArgumentException("Unknown cell id: {$id}");
    }

    public function cellAt(int $row, int $col): LayoutCell
    {
        return $this->cell(LayoutCell::idFor($row, $col));
    }

    public function edge(string $key): LayoutEdge
    {
        return $this->edges[$key] ?? throw new InvalidArgumentException("Unknown edge: {$key}");
    }
}
