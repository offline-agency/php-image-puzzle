<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\PieceContext;

/**
 * Sinusoidal (wavy) edge: multiple waves along each edge.
 * Transparency follows a sine wave so the boundary wiggles.
 */
final class SinusStyle implements EdgeStyleInterface
{
    /** Number of wave cycles along the edge. */
    private const CYCLES = 2;

    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        ClassicEdgeHelper::applyProfiles($piece, $context, function (int $length, bool $isTab): array {
            $profile = [];
            if ($length <= 0) {
                return $profile;
            }
            for ($i = 0; $i < $length; $i++) {
                $t = $i / (float) $length;
                $wave = sin(2.0 * M_PI * self::CYCLES * $t);
                $transparent = $wave < 0;
                $profile[$i] = $isTab ? $transparent : $transparent;
            }
            return $profile;
        });
        return $piece;
    }
}
