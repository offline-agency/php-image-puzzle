<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry;

/**
 * Integer pixel rectangle in source/world coordinates.
 */
final readonly class Bounds
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}
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
