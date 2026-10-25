<?php

declare(strict_types=1);

namespace ImagePuzzle\Cache;

use ImagePuzzle\Game\PuzzleDirectory;

final readonly class CachedPuzzle
{
    public function __construct(
        public PuzzleDirectory $puzzle,
        public bool $hit,
    ) {
    }
}
