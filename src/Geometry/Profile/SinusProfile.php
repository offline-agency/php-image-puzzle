<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Profile;

use ImagePuzzle\Geometry\Rng;

/**
 * Smooth wave across the whole edge: offset(t) = A * scale * sin(2 pi t) * sin(pi t).
 *
 * The sin(pi t) window makes the curve leave both corners tangentially, so
 * waves of neighbouring edges never cross at a shared corner. One neighbour
 * receives a bump followed by a dip; the other the exact complement.
 * Peak depth is about 0.12 scale; the seeded variation scales the amplitude
 * by 0.9..1.1.
 */
final class SinusProfile implements EdgeProfileInterface
{
    private const AMPLITUDE = 0.16;
    private const SAMPLES = 40;

    public function name(): string
    {
        return 'sinus';
    }

    public function isFlat(): bool
    {
        return false;
    }

    public function points(float $length, float $scale, Rng $rng): array
    {
        $amplitude = self::AMPLITUDE * $scale * $rng->range(0.9, 1.1);
        $points = [[0.0, 0.0]];
        for ($i = 1; $i < self::SAMPLES; $i++) {
            $t = $i / self::SAMPLES;
            $points[] = [$t * $length, $amplitude * sin(2 * M_PI * $t) * sin(M_PI * $t)];
        }
        $points[] = [$length, 0.0];

        return $points;
    }
}
