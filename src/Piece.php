<?php

declare(strict_types=1);

namespace ImagePuzzle;

use GdImage;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\Geometry\Bounds;
use ImagePuzzle\Geometry\PieceRenderer;
use ImagePuzzle\Geometry\PieceShape;

/**
 * One piece: its logical cell, its outline and a lazy renderer.
 *
 * The source image is shared, never copied, mutated or destroyed by the
 * piece. toGdImage() returns a new image owned by the caller.
 */
final class Piece
{
    public function __construct(
        private readonly PieceShape $shape,
        private readonly GdImage $sourceImage,
        private readonly int $sourceWidth,
        private readonly int $sourceHeight,
        private readonly int $totalRows,
        private readonly int $totalCols,
        private readonly EdgeStyleInterface $edgeStyle,
        private readonly PieceRenderer $renderer = new PieceRenderer(),
    ) {
    }

    public function getId(): string
    {
        return $this->shape->id;
    }

    public function getRow(): int
    {
        return $this->shape->row;
    }

    public function getCol(): int
    {
        return $this->shape->col;
    }

    /** Logical cell X offset in the source. */
    public function getX(): int
    {
        return $this->shape->logical->x;
    }

    /** Logical cell Y offset in the source. */
    public function getY(): int
    {
        return $this->shape->logical->y;
    }

    /** Logical cell width. */
    public function getWidth(): int
    {
        return $this->shape->logical->width;
    }

    /** Logical cell height. */
    public function getHeight(): int
    {
        return $this->shape->logical->height;
    }

    /** Placement and size of the rendered image in source coordinates. */
    public function getRenderedBounds(): Bounds
    {
        return $this->shape->rendered;
    }

    public function getShape(): PieceShape
    {
        return $this->shape;
    }

    public function getContext(): PieceContext
    {
        $logical = $this->shape->logical;

        return new PieceContext(
            $this->shape->row,
            $this->shape->col,
            $logical->x,
            $logical->y,
            $logical->width,
            $logical->height,
            $this->sourceWidth,
            $this->sourceHeight,
            $this->totalRows,
            $this->totalCols,
            $this->shape->rendered,
            $this->shape->id,
            $this->shape->border,
        );
    }

    /**
     * Render the piece. The returned image has the rendered bounds' size and
     * must be placed at getRenderedBounds()->x/y to reconstruct the source.
     */
    public function toGdImage(): GdImage
    {
        return $this->edgeStyle->apply($this->renderer->render($this->sourceImage, $this->shape), $this->getContext());
    }
}
