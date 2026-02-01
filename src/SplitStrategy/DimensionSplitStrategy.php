<?php

declare(strict_types=1);

namespace ImagePuzzle\SplitStrategy;

/**
 * Strategy for splitting a dimension (width or height) into segment sizes.
 * Implement this to customize how the image grid is divided (e.g. remainder distribution).
 */
interface DimensionSplitStrategy
{
    /**
     * Split a dimension into segment sizes.
     *
     * @return list<int> Segment sizes; must have exactly $segmentCount elements and sum to $totalSize
     */
    public function split(int $totalSize, int $segmentCount): array;
}
