<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\PieceContext;

final class NoEdgeStyle implements EdgeStyleInterface
{
    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        return $piece;
    }
}
