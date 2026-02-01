<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\PieceContext;

/**
 * Classic jigsaw edge: combination of straight and curved (ellipse-like) segments.
 * Tab = opaque bump in the middle of the edge; socket = transparent dent.
 * Adjacent pieces fit: right/bottom edges are tabs, left/top are sockets.
 */
final class JigsawStyle implements EdgeStyleInterface
{
    /** Fraction of edge length for the tab/socket (middle segment). 0.5 = center half. */
    private const MIDDLE_FRACTION = 0.5;

    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        ClassicEdgeHelper::applyProfiles($piece, $context, function (int $length, bool $isTab): array {
            $profile = [];
            $start = (int) floor($length * (1 - self::MIDDLE_FRACTION) / 2);
            $end = (int) ceil($length * (1 + self::MIDDLE_FRACTION) / 2);
            for ($i = 0; $i < $length; $i++) {
                $inMiddle = $i >= $start && $i < $end;
                $profile[$i] = $isTab ? !$inMiddle : $inMiddle;
            }
            return $profile;
        });
        return $piece;
    }
}
