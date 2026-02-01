<?php

declare(strict_types=1);

namespace ImagePuzzle\SplitStrategy;

/**
 * Default split: base = floor(total/count), remainder pixels assigned to the last segment.
 */
final class DefaultSplitStrategy implements DimensionSplitStrategy
{
    public function split(int $totalSize, int $segmentCount): array
    {
        $base = (int) floor($totalSize / $segmentCount);
        $remainder = $totalSize % $segmentCount;
        $splits = [];
        for ($i = 0; $i < $segmentCount; $i++) {
            $splits[] = $base + ($i === $segmentCount - 1 ? $remainder : 0);
        }
        return $splits;
    }
}
