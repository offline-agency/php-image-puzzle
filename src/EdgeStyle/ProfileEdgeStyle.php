<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\Geometry\BoundaryProfileProvider;
use ImagePuzzle\Geometry\Profile\EdgeProfileInterface;
use ImagePuzzle\Geometry\Topology\LayoutEdge;
use ImagePuzzle\PieceContext;

/**
 * Base for interlocking styles. The piece outline is produced by the shared
 * board geometry (one profile per internal boundary, flat board perimeter);
 * apply() adds no raster effect on top of the rendered shape.
 */
abstract class ProfileEdgeStyle implements EdgeStyleInterface, BoundaryProfileProvider
{
    abstract public function profile(): EdgeProfileInterface;

    public function profileForBoundary(LayoutEdge $edge): EdgeProfileInterface
    {
        return $this->profile();
    }

    final public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        return $piece;
    }
}
