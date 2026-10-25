<?php

declare(strict_types=1);

namespace ImagePuzzle;

use ImagePuzzle\Geometry\Bounds;

/**
 * Read-only information about the piece an edge style is applied to.
 * x/y/width/height describe the logical cell; the rendered bounds describe the
 * piece image (larger than the cell when tabs protrude).
 */
final class PieceContext
{
    private readonly Bounds $rendered;

    public function __construct(
        private readonly int $row,
        private readonly int $col,
        private readonly int $x,
        private readonly int $y,
        private readonly int $width,
        private readonly int $height,
        private readonly int $sourceWidth,
        private readonly int $sourceHeight,
        private readonly int $totalRows,
        private readonly int $totalCols,
        ?Bounds $rendered = null,
        private readonly ?string $id = null,
        private readonly ?bool $border = null,
    ) {
        if ($width < 1 || $height < 1) {
            throw new \ImagePuzzle\Exception\InvalidArgumentException("Piece context must be at least 1x1, got {$width}x{$height}.");
        }
        $this->rendered = $rendered ?? new Bounds($x, $y, $width, $height);
    }

    public function getRow(): int
    {
        return $this->row;
    }

    public function getCol(): int
    {
        return $this->col;
    }

    public function getX(): int
    {
        return $this->x;
    }

    public function getY(): int
    {
        return $this->y;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getSourceWidth(): int
    {
        return $this->sourceWidth;
    }

    public function getSourceHeight(): int
    {
        return $this->sourceHeight;
    }

    public function getTotalRows(): int
    {
        return $this->totalRows;
    }

    public function getTotalCols(): int
    {
        return $this->totalCols;
    }

    public function getRenderedBounds(): Bounds
    {
        return $this->rendered;
    }

    public function getId(): string
    {
        return $this->id ?? "r{$this->row}c{$this->col}";
    }

    /**
     * Whether the piece touches the board perimeter. Pieces built by the
     * generator know this from their layout (any topology); hand-built
     * contexts fall back to the rectangular row/column rule.
     */
    public function isBorder(): bool
    {
        if ($this->border !== null) {
            return $this->border;
        }

        return $this->row === 0 || $this->col === 0 || $this->row === $this->totalRows - 1 || $this->col === $this->totalCols - 1;
    }
}
