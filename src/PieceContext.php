<?php

declare(strict_types=1);

namespace ImagePuzzle;

final class PieceContext
{
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
    ) {
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
}
