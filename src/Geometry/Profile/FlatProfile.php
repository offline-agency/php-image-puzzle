<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Profile;

use ImagePuzzle\Geometry\Rng;

/**
 * Straight boundary: rectangular slicing.
 */
final class FlatProfile implements EdgeProfileInterface
{
    public function name(): string
    {
        return 'flat';
    }

    public function isFlat(): bool
    {
        return true;
    }

    public function points(float $length, float $scale, Rng $rng): array
    {
        return [[0.0, 0.0], [$length, 0.0]];
    }
}
