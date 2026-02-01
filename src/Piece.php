<?php

declare(strict_types=1);

namespace ImagePuzzle;

use GdImage;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;

final class Piece
{
    public function __construct(
        private readonly int $row,
        private readonly int $col,
        private readonly int $x,
        private readonly int $y,
        private readonly int $width,
        private readonly int $height,
        private readonly GdImage $sourceImage,
        private readonly int $sourceWidth,
        private readonly int $sourceHeight,
        private readonly int $totalRows,
        private readonly int $totalCols,
        private readonly EdgeStyleInterface $edgeStyle,
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

    public function toGdImage(): GdImage
    {
        $piece = $this->createPieceImage();
        $this->copySourceRegion($piece);
        $context = new PieceContext(
            $this->row,
            $this->col,
            $this->x,
            $this->y,
            $this->width,
            $this->height,
            $this->sourceWidth,
            $this->sourceHeight,
            $this->totalRows,
            $this->totalCols,
        );
        return $this->edgeStyle->apply($piece, $context);
    }

    private function createPieceImage(): GdImage
    {
        $image = imagecreatetruecolor($this->width, $this->height);
        if ($image === false) {
            throw new \RuntimeException('Failed to create piece image');
        }
        imagesavealpha($image, true);
        imagealphablending($image, false);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefill($image, 0, 0, $transparent);
        }
        imagealphablending($image, true);
        return $image;
    }

    private function copySourceRegion(GdImage $piece): void
    {
        imagecopy(
            $piece,
            $this->sourceImage,
            0,
            0,
            $this->x,
            $this->y,
            $this->width,
            $this->height
        );
    }
}
