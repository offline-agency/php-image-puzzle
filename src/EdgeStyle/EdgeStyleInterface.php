<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\PieceContext;

interface EdgeStyleInterface
{
    /**
     * Apply the edge style to a piece image. May return the same image or a new one.
     */
    public function apply(GdImage $piece, PieceContext $context): GdImage;
}
