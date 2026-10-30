<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\Geometry\BoundaryProfileProvider;
use ImagePuzzle\Geometry\Profile\EdgeProfileInterface;
use ImagePuzzle\Geometry\Profile\FlatProfile;
use ImagePuzzle\Geometry\Topology\LayoutEdge;
use ImagePuzzle\PieceContext;

/**
 * Rectangular pieces with straight edges. Same output as NoEdgeStyle; kept as
 * the "line" config type.
 */
final class LineStyle implements EdgeStyleInterface, BoundaryProfileProvider
{
    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        return $piece;
    }

    public function profileForBoundary(LayoutEdge $edge): EdgeProfileInterface
    {
        return new FlatProfile();
    }
}
