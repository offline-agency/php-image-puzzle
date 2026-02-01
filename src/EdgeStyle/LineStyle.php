<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\PieceContext;

/**
 * Rectangular pieces with no tab/socket (straight edges).
 * Equivalent to the legacy "line" edge; same effect as NoEdgeStyle.
 */
final class LineStyle implements EdgeStyleInterface
{
    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        return $piece;
    }
}
