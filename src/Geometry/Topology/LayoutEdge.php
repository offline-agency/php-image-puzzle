<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Topology;

/**
 * A boundary between two cells, or an external board edge (one cell only).
 *
 * The forward direction runs from $a to $b; the cell that references the edge
 * with reversed=false is $forwardCell, the other is $reverseCell.
 */
final readonly class LayoutEdge
{
    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     * @param string $orientation "horizontal", "vertical" or "diagonal"
     * @param float $scale tab size reference in pixels, shared by both neighbours
     */
    public function __construct(
        public string $key,
        public array $a,
        public array $b,
        public ?string $forwardCell,
        public ?string $reverseCell,
        public string $orientation,
        public float $scale,
    ) {
    }

    public function isExternal(): bool
    {
        return $this->forwardCell === null || $this->reverseCell === null;
    }

    public function length(): float
    {
        return hypot($this->b[0] - $this->a[0], $this->b[1] - $this->a[1]);
    }
}
