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
 * Plain rectangular slicing (the default).
 */
final class NoEdgeStyle implements EdgeStyleInterface, BoundaryProfileProvider
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
