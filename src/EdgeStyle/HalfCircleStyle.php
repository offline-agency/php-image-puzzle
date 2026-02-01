<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\PieceContext;

/**
 * Half-circle tab/socket on each edge.
 * Tab = opaque semicircle in the middle; socket = transparent half-circle dent.
 */
final class HalfCircleStyle implements EdgeStyleInterface
{
    /** Fraction of edge length for the half-circle diameter. */
    private const DIAMETER_FRACTION = 0.5;

    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        ClassicEdgeHelper::applyProfiles($piece, $context, function (int $length, bool $isTab): array {
            $profile = [];
            $radius = $length * self::DIAMETER_FRACTION / 2.0;
            $center = ($length - 1) / 2.0;
            for ($i = 0; $i < $length; $i++) {
                $dx = $i - $center;
                $inHalfCircle = $dx * $dx <= $radius * $radius;
                $profile[$i] = $isTab ? !$inHalfCircle : $inHalfCircle;
            }
            return $profile;
        });
        return $piece;
    }
}
