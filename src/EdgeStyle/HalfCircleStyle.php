<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use ImagePuzzle\Geometry\Profile\EdgeProfileInterface;
use ImagePuzzle\Geometry\Profile\HalfCircleProfile;

/**
 * Semicircular tabs/sockets on internal boundaries; flat perimeter.
 */
final class HalfCircleStyle extends ProfileEdgeStyle
{
    public function profile(): EdgeProfileInterface
    {
        return new HalfCircleProfile();
    }
}
