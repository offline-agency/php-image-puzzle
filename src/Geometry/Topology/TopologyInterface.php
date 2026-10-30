<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Topology;

use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;

interface TopologyInterface
{
    public function name(): string;

    /**
     * Build the board for a source of $width x $height pixels.
     * $rows/$cols are topology-specific counts (see each implementation).
     */
    public function layout(int $width, int $height, int $rows, int $cols, ?DimensionSplitStrategy $splitStrategy = null): Layout;
}
