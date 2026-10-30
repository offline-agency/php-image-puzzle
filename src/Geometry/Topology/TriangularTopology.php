<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Topology;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;

/**
 * Alternating up/down triangles in horizontal strips.
 *
 * $rows strips of height H / rows; on horizontal line k the vertices sit at
 * multiples of W / cols, shifted by half a step on odd lines. Each strip is
 * triangulated left to right, so each row holds 2 * cols + 1 cells, the first
 * and last being right-angled half triangles along the image border.
 * Tabs use half the boundary length as their scale so the three sockets of a
 * triangle cannot meet near its centre.
 */
final class TriangularTopology implements TopologyInterface
{
    public function name(): string
    {
        return 'triangle';
    }

    public function layout(int $width, int $height, int $rows, int $cols, ?DimensionSplitStrategy $splitStrategy = null): Layout
    {
        if ($splitStrategy !== null) {
            throw new InvalidArgumentException('Custom split strategies apply to rectangular boards only.');
        }
        if ($rows < 1 || $cols < 1) {
            throw new InvalidArgumentException('A triangular board needs at least 1 row and 1 column.');
        }
        $dx = $width / $cols;
        $dy = $height / $rows;
        if ($dx < 4 || $dy < 4) {
            throw new InvalidArgumentException("A {$rows}x{$cols} triangular board is too dense for a {$width}x{$height} image (cells need at least 4 pixels).");
        }

        // Coordinates come from integer lattice indices only (and are always
        // floats), so shared vertices are bit-identical across cells.
        $line = static function (int $k) use ($cols, $rows, $width, $height): array {
            $y = (float) ($k * $height / $rows);
            $xs = [0.0];
            for ($i = 0; $i <= 2 * $cols; $i++) {
                if ($i % 2 === $k % 2) {
                    continue;
                }
                $x = $i * $width / (2.0 * $cols);
                if ($x > 0.0 && $x < $width) {
                    $xs[] = $x;
                }
            }
            $xs[] = (float) $width;

            return array_map(static fn (float $x): array => [$x, $y], $xs);
        };

        $cells = [];
        for ($r = 0; $r < $rows; $r++) {
            $top = $line($r);
            $bottom = $line($r + 1);
            $i = 0;
            $j = 0;
            $c = 0;
            while ($i < count($top) - 1 || $j < count($bottom) - 1) {
                // Advance along the line whose next segment is further left
                // (midpoints never tie on this lattice): this yields the
                // alternating up/down triangles, including the end halves.
                $advanceTop = $j >= count($bottom) - 1
                    || ($i < count($top) - 1 && $top[$i][0] + $top[$i + 1][0] < $bottom[$j][0] + $bottom[$j + 1][0]);
                if ($advanceTop) {
                    $polygon = [$top[$i], $top[$i + 1], $bottom[$j]];
                    $i++;
                } else {
                    $polygon = [$top[$i], $bottom[$j + 1], $bottom[$j]];
                    $j++;
                }
                $cells[] = ['id' => LayoutCell::idFor($r, $c), 'row' => $r, 'col' => $c, 'polygon' => $polygon];
                $c++;
            }
        }

        return PolygonLayoutBuilder::build('triangle', $width, $height, $cells, 0.5);
    }
}
