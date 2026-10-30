<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Topology;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;

/**
 * Pointy-top hexagonal board clipped to the image rectangle.
 *
 * $rows is the number of cell rows (>= 2); $cols the number of whole hexagons
 * in odd rows (>= 1). Even rows hold $cols + 1 cells whose first and last
 * cells are half hexagons; the first and last rows are cut in half by the
 * image border, so the board perimeter is straight and no sliver cells exist.
 * Hexagons stretch to the image aspect ratio: column spacing W / cols, row
 * spacing H / (rows - 1).
 */
final class HexagonalTopology implements TopologyInterface
{
    public function name(): string
    {
        return 'hex';
    }

    public function layout(int $width, int $height, int $rows, int $cols, ?DimensionSplitStrategy $splitStrategy = null): Layout
    {
        if ($splitStrategy !== null) {
            throw new InvalidArgumentException('Custom split strategies apply to rectangular boards only.');
        }
        if ($rows < 2 || $cols < 1) {
            throw new InvalidArgumentException('A hexagonal board needs at least 2 rows and 1 column.');
        }
        $dx = $width / $cols;
        $dy = $height / ($rows - 1);
        if ($dx < 4 || $dy < 4) {
            throw new InvalidArgumentException("A {$rows}x{$cols} hexagonal board is too dense for a {$width}x{$height} image (cells need at least 4 pixels).");
        }
        // Hexagon height is 4/3 of the row spacing, so every vertex lies on a
        // lattice of W / (2 cols) by H / (3 (rows - 1)). Coordinates are
        // computed from integer lattice indices only, so shared corners are
        // bit-identical in every cell that uses them.
        $vx = static fn (int $halfColumn): float => $halfColumn * $width / (2.0 * $cols);
        $vy = static fn (int $row, int $third): float => (3 * $row + $third) * $height / (3.0 * ($rows - 1));

        $cells = [];
        for ($r = 0; $r < $rows; $r++) {
            $even = $r % 2 === 0;
            $count = $even ? $cols + 1 : $cols;
            for ($c = 0; $c < $count; $c++) {
                // Centre in half-column units: even rows at 2c, odd rows at 2c + 1.
                $hc = $even ? 2 * $c : 2 * $c + 1;
                $hexagon = [
                    [$vx($hc), $vy($r, -2)],
                    [$vx($hc + 1), $vy($r, -1)],
                    [$vx($hc + 1), $vy($r, 1)],
                    [$vx($hc), $vy($r, 2)],
                    [$vx($hc - 1), $vy($r, 1)],
                    [$vx($hc - 1), $vy($r, -1)],
                ];
                $polygon = PolygonLayoutBuilder::clip($hexagon, (float) $width, (float) $height);
                if ($polygon === []) {
                    continue;
                }
                $cells[] = ['id' => LayoutCell::idFor($r, $c), 'row' => $r, 'col' => $c, 'polygon' => $polygon];
            }
        }

        return PolygonLayoutBuilder::build('hex', $width, $height, $cells, 1.0);
    }
}
