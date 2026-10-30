<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Profile;

/**
 * Classic jigsaw knob: a narrow neck widening into a rounded head.
 * Width 0.70 scale (shoulder to shoulder), head width ~0.35, depth 0.245.
 */
final class JigsawProfile extends TabProfile
{
    /** @var list<array{0: float, 1: float}>|null */
    private static ?array $cache = null;

    public function name(): string
    {
        return 'jigsaw';
    }

    protected function tab(): array
    {
        return self::$cache ??= self::sampleBeziers([
            [[-0.35, 0.0], [-0.20, 0.0], [-0.07, 0.0], [-0.09, 0.05]],
            [[-0.09, 0.05], [-0.11, 0.09], [-0.19, 0.10], [-0.16, 0.15]],
            [[-0.16, 0.15], [-0.13, 0.22], [-0.07, 0.245], [0.0, 0.245]],
            [[0.0, 0.245], [0.07, 0.245], [0.13, 0.22], [0.16, 0.15]],
            [[0.16, 0.15], [0.19, 0.10], [0.11, 0.09], [0.09, 0.05]],
            [[0.09, 0.05], [0.07, 0.0], [0.20, 0.0], [0.35, 0.0]],
        ], 10);
    }
}
