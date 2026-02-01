<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\PieceContext;

/**
 * Shared logic for classic tab/socket edge styles.
 * Applies a 1D transparency profile to each of the four edges.
 */
final class ClassicEdgeHelper
{
    /**
     * Apply edge profiles to the piece. For each edge, call $profileForEdge with
     * (edgeLength, isTab) and use the returned array of bool (index => transparent).
     *
     * @param callable(int, bool): array<int, bool> $profileForEdge (length, isTab) => [pos => transparent]
     */
    public static function applyProfiles(
        GdImage $piece,
        PieceContext $context,
        callable $profileForEdge,
    ): void {
        $width = $context->getWidth();
        $height = $context->getHeight();
        $transparent = imagecolorallocatealpha($piece, 0, 0, 0, 127);
        if ($transparent === false) {
            return;
        }
        imagesavealpha($piece, true);
        imagealphablending($piece, false);

        // Top edge (y=0): socket = transparent in middle
        $topProfile = $profileForEdge($width, false);
        for ($x = 0; $x < $width; $x++) {
            if (($topProfile[$x] ?? false)) {
                imagesetpixel($piece, $x, 0, $transparent);
            }
        }

        // Bottom edge (y=height-1): tab = opaque in middle, so transparent at ends
        $bottomProfile = $profileForEdge($width, true);
        for ($x = 0; $x < $width; $x++) {
            if (($bottomProfile[$x] ?? false)) {
                imagesetpixel($piece, $x, $height - 1, $transparent);
            }
        }

        // Left edge (x=0): socket
        $leftProfile = $profileForEdge($height, false);
        for ($y = 0; $y < $height; $y++) {
            if (($leftProfile[$y] ?? false)) {
                imagesetpixel($piece, 0, $y, $transparent);
            }
        }

        // Right edge (x=width-1): tab
        $rightProfile = $profileForEdge($height, true);
        for ($y = 0; $y < $height; $y++) {
            if (($rightProfile[$y] ?? false)) {
                imagesetpixel($piece, $width - 1, $y, $transparent);
            }
        }

        imagealphablending($piece, true);
    }
}
