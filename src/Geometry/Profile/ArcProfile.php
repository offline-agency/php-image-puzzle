<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Profile;

/**
 * Shallow circular-arc bump: chord 0.50 scale, sagitta 0.15 scale
 * (circle radius ~0.283 scale), meeting the edge at an angle.
 */
final class ArcProfile extends TabProfile
{
    private const HALF_CHORD = 0.25;
    private const SAGITTA = 0.15;

    /** @var list<array{0: float, 1: float}>|null */
    private static ?array $cache = null;

    public function name(): string
    {
        return 'arc';
    }

    protected function tab(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $radius = (self::HALF_CHORD ** 2 + self::SAGITTA ** 2) / (2 * self::SAGITTA);
        $cy = self::SAGITTA - $radius;
        $half = asin(self::HALF_CHORD / $radius);
        // From the left chord end over the top to the right chord end.
        $points = self::sampleArc(0.0, $cy, $radius, M_PI / 2 + $half, M_PI / 2 - $half, 24);
        $points[0] = [-self::HALF_CHORD, 0.0];
        $points[count($points) - 1] = [self::HALF_CHORD, 0.0];

        return self::$cache = $points;
    }
}
