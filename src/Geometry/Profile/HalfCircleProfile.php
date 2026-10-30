<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Profile;

/**
 * Semicircular tab of radius 0.18 scale centred on the edge.
 */
final class HalfCircleProfile extends TabProfile
{
    private const RADIUS = 0.18;

    /** @var list<array{0: float, 1: float}>|null */
    private static ?array $cache = null;

    public function name(): string
    {
        return 'half_circle';
    }

    protected function tab(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $points = self::sampleArc(0.0, 0.0, self::RADIUS, M_PI, 0.0, 32);
        $points[0] = [-self::RADIUS, 0.0];
        $points[count($points) - 1] = [self::RADIUS, 0.0];

        return self::$cache = $points;
    }
}
