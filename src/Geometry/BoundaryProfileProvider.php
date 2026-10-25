<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry;

use ImagePuzzle\Geometry\Profile\EdgeProfileInterface;
use ImagePuzzle\Geometry\Topology\LayoutEdge;

/**
 * Chooses the profile of each internal boundary. It is consulted exactly once
 * per shared boundary, so both neighbours always receive complementary shapes.
 * External board edges are always flat and never passed here.
 */
interface BoundaryProfileProvider
{
    public function profileForBoundary(LayoutEdge $edge): EdgeProfileInterface;
}
