<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use ImagePuzzle\Geometry\Profile\EdgeProfileInterface;
use ImagePuzzle\Geometry\Profile\SinusProfile;

/**
 * Smooth sine-wave boundaries between pieces (bump on one neighbour, the
 * complementary dip on the other); flat perimeter.
 */
final class SinusStyle extends ProfileEdgeStyle
{
    public function profile(): EdgeProfileInterface
    {
        return new SinusProfile();
    }
}
