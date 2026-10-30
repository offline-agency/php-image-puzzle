<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use ImagePuzzle\Geometry\Profile\EdgeProfileInterface;
use ImagePuzzle\Geometry\Profile\JigsawProfile;

/**
 * Classic interlocking jigsaw pieces: every internal boundary carries a knob
 * (tab on one neighbour, matching socket on the other); the board perimeter
 * stays flat. Tab direction is chosen per boundary from the seed.
 */
final class JigsawStyle extends ProfileEdgeStyle
{
    public function profile(): EdgeProfileInterface
    {
        return new JigsawProfile();
    }
}
