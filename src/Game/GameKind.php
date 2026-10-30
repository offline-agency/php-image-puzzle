<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

/**
 * The game a definition describes. A kind names rules, never a visual style:
 * "jigsaw" is played on any board topology, and edge styles stay geometry.
 */
enum GameKind: string
{
    case Sliding = 'sliding';
    case Swap = 'swap';
    case Rotation = 'rotation';
    case Strips = 'strips';
    case Jigsaw = 'jigsaw';
    case Memory = 'memory';
    case Tangram = 'tangram';
    case Nonogram = 'nonogram';
}
