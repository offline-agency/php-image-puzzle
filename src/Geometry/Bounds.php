<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry;

/**
 * Integer pixel rectangle in source/world coordinates.
 */
final readonly class Bounds
{
    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
        // Guards callers that bypass static analysis.
        if ($width < 1 || $height < 1) {
            throw new \ImagePuzzle\Exception\InvalidArgumentException("Bounds must be at least 1x1, got {$width}x{$height}.");
        }
    }

    /**
     * @return array{x: int, y: int, width: positive-int, height: positive-int}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
    }

    public function contains(Bounds $other): bool
    {
        return $other->x >= $this->x
            && $other->y >= $this->y
            && $other->x + $other->width <= $this->x + $this->width
            && $other->y + $other->height <= $this->y + $this->height;
    }
}
