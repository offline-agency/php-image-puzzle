<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use ImagePuzzle\Geometry\Profile\ArcProfile;
use ImagePuzzle\Geometry\Profile\EdgeProfileInterface;

/**
 * Shallow circular-arc tabs/sockets on internal boundaries; flat perimeter.
 */
final class ArcStyle extends ProfileEdgeStyle
{
    public function profile(): EdgeProfileInterface
    {
        return new ArcProfile();
    }
}
