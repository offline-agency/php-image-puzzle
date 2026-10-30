<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\Geometry\Bounds;

/**
 * Deterministic shelf packing of rectangles into one atlas.
 *
 * Items are placed tallest first (then widest, then in input order) on
 * left-to-right shelves of a width close to the square root of the total
 * area, with $padding transparent pixels around every frame. The same sizes
 * always produce the same atlas, independent of PHP version or hash order.
 */
final class AtlasPacker
{
    /**
     * @param array<string, array{0: int<1, max>, 1: int<1, max>}> $sizes width and height by ID
     * @return array{width: int<1, max>, height: int<1, max>, frames: array<string, Bounds>} frames in input order
     */
    public static function pack(array $sizes, int $padding, int $maxSize): array
    {
        if ($sizes === []) {
            return ['width' => 1, 'height' => 1, 'frames' => []];
        }
        $order = [];
        $area = 0;
        $widest = 0;
        $index = 0;
        foreach ($sizes as $id => [$width, $height]) {
            $order[] = [$height, $width, $index++, (string) $id];
            $area += ($width + $padding) * ($height + $padding);
            $widest = max($widest, $width);
        }
        usort($order, static fn (array $a, array $b): int => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);

        $atlasWidth = max($widest + 2 * $padding, (int) ceil(sqrt($area)));
        $atlasWidth = max(4, (int) (ceil($atlasWidth / 4) * 4));
        $placed = [];
        $x = $padding;
        $y = $padding;
        $shelf = 0;
        foreach ($order as [$height, $width, , $id]) {
            if ($x + $width + $padding > $atlasWidth) {
                $x = $padding;
                $y += $shelf + $padding;
                $shelf = 0;
            }
            $placed[$id] = new Bounds($x, $y, $width, $height);
            $x += $width + $padding;
            $shelf = max($shelf, $height);
        }
        $atlasHeight = max(1, $y + $shelf + $padding);
        if ($atlasWidth > $maxSize || $atlasHeight > $maxSize) {
            throw new ResourceLimitExceededException("The atlas would be {$atlasWidth}x{$atlasHeight} pixels, over the {$maxSize} px limit; pack a smaller resolution or raise the limit.");
        }
        $frames = [];
        foreach (array_keys($sizes) as $id) {
            $frames[(string) $id] = $placed[(string) $id];
        }

        return ['width' => $atlasWidth, 'height' => $atlasHeight, 'frames' => $frames];
    }
}
