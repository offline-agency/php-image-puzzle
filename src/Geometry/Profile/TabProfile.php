<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Profile;

use ImagePuzzle\Geometry\Rng;

/**
 * Base for profiles made of a straight shoulder, a centred tab and a straight
 * shoulder. Subclasses describe the tab in scale units centred on 0, from
 * (-halfWidth, 0) to (+halfWidth, 0), with positive offsets for the bump.
 *
 * Seeded variation: the tab centre moves by up to +/-0.04 scale along the
 * edge and the tab is scaled by 0.94..1.04. The bounds guarantee the tab stays
 * inside the middle 0.5 scale of any edge and below MAX_DEPTH.
 */
abstract class TabProfile implements EdgeProfileInterface
{
    final public function isFlat(): bool
    {
        return false;
    }

    /**
     * @return list<array{0: float, 1: float}> tab points in scale units, first (-w, 0), last (w, 0)
     */
    abstract protected function tab(): array;

    final public function points(float $length, float $scale, Rng $rng): array
    {
        $shift = $rng->range(-0.04, 0.04) * $scale;
        $size = $rng->range(0.94, 1.04) * $scale;
        $center = $length / 2.0 + $shift;

        $points = [[0.0, 0.0]];
        foreach ($this->tab() as [$u, $v]) {
            $points[] = [$center + $u * $size, $v * $size];
        }
        $points[] = [$length, 0.0];

        return $points;
    }

    /**
     * Sample a chain of cubic Bezier segments.
     *
     * @param list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}, 2: array{0: float, 1: float}, 3: array{0: float, 1: float}}> $segments
     * @return list<array{0: float, 1: float}>
     */
    protected static function sampleBeziers(array $segments, int $steps): array
    {
        $points = [];
        foreach ($segments as $index => [$p0, $p1, $p2, $p3]) {
            for ($i = $index === 0 ? 0 : 1; $i <= $steps; $i++) {
                $t = $i / $steps;
                $mt = 1.0 - $t;
                $a = $mt * $mt * $mt;
                $b = 3.0 * $mt * $mt * $t;
                $c = 3.0 * $mt * $t * $t;
                $d = $t * $t * $t;
                $points[] = [
                    $a * $p0[0] + $b * $p1[0] + $c * $p2[0] + $d * $p3[0],
                    $a * $p0[1] + $b * $p1[1] + $c * $p2[1] + $d * $p3[1],
                ];
            }
        }

        return $points;
    }

    /**
     * Sample a circular arc from angle $from to $to (radians) around ($cx, $cy).
     *
     * @return list<array{0: float, 1: float}>
     */
    protected static function sampleArc(float $cx, float $cy, float $radius, float $from, float $to, int $steps): array
    {
        $points = [];
        for ($i = 0; $i <= $steps; $i++) {
            $angle = $from + ($to - $from) * $i / $steps;
            $points[] = [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];
        }

        return $points;
    }
}
