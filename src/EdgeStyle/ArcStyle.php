<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\PieceContext;

/**
 * Arc-shaped tab/socket along edges.
 * Tab = opaque arc segment in the middle; socket = transparent arc dent.
 */
final class ArcStyle implements EdgeStyleInterface
{
    /** Fraction of edge length for the arc segment. */
    private const ARC_FRACTION = 0.45;

    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        ClassicEdgeHelper::applyProfiles($piece, $context, function (int $length, bool $isTab): array {
            $profile = [];
            $half = $length / 2.0;
            $radius = $length * self::ARC_FRACTION / 2.0;
            for ($i = 0; $i < $length; $i++) {
                $x = $i - $half;
                $ySq = $radius * $radius - $x * $x;
                $inArc = $ySq >= 0 && abs($x) <= $radius;
                $profile[$i] = $isTab ? !$inArc : $inArc;
            }
            return $profile;
        });
        return $piece;
    }
}
