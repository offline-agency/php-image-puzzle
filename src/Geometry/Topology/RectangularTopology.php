<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Topology;

use ImagePuzzle\Geometry\Grid;
use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;

/**
 * Rows x cols rectangular cells. Edge keys: "h{r}_{c}" is the horizontal
 * boundary at y = yOffsets[r] above cell (r, c); "v{r}_{c}" is the vertical
 * boundary at x = xOffsets[c] left of cell (r, c).
 */
final class RectangularTopology implements TopologyInterface
{
    public function name(): string
    {
        return 'rect';
    }

    public function layout(int $width, int $height, int $rows, int $cols, ?DimensionSplitStrategy $splitStrategy = null): Layout
    {
        return self::fromGrid(Grid::create($width, $height, $rows, $cols, $splitStrategy));
    }

    public static function fromGrid(Grid $grid): Layout
    {
        $xs = $grid->xOffsets;
        $ys = $grid->yOffsets;
        $edges = [];

        for ($r = 0; $r <= $grid->rows; $r++) {
            for ($c = 0; $c < $grid->cols; $c++) {
                $above = $r > 0 ? LayoutCell::idFor($r - 1, $c) : null;
                $below = $r < $grid->rows ? LayoutCell::idFor($r, $c) : null;
                $scale = (float) min(
                    $grid->colWidths[$c],
                    $r > 0 ? $grid->rowHeights[$r - 1] : PHP_INT_MAX,
                    $r < $grid->rows ? $grid->rowHeights[$r] : PHP_INT_MAX,
                );
                $key = "h{$r}_{$c}";
                // Forward (left -> right) is the top edge of the cell below.
                $edges[$key] = new LayoutEdge($key, [(float) $xs[$c], (float) $ys[$r]], [(float) $xs[$c + 1], (float) $ys[$r]], $below, $above, 'horizontal', $scale);
            }
        }
        for ($r = 0; $r < $grid->rows; $r++) {
            for ($c = 0; $c <= $grid->cols; $c++) {
                $left = $c > 0 ? LayoutCell::idFor($r, $c - 1) : null;
                $right = $c < $grid->cols ? LayoutCell::idFor($r, $c) : null;
                $scale = (float) min(
                    $grid->rowHeights[$r],
                    $c > 0 ? $grid->colWidths[$c - 1] : PHP_INT_MAX,
                    $c < $grid->cols ? $grid->colWidths[$c] : PHP_INT_MAX,
                );
                $key = "v{$r}_{$c}";
                // Forward (top -> bottom) is the right edge of the cell on the left.
                $edges[$key] = new LayoutEdge($key, [(float) $xs[$c], (float) $ys[$r]], [(float) $xs[$c], (float) $ys[$r + 1]], $left, $right, 'vertical', $scale);
            }
        }

        $cells = [];
        for ($r = 0; $r < $grid->rows; $r++) {
            for ($c = 0; $c < $grid->cols; $c++) {
                $x0 = (float) $xs[$c];
                $x1 = (float) $xs[$c + 1];
                $y0 = (float) $ys[$r];
                $y1 = (float) $ys[$r + 1];
                $cells[] = new LayoutCell(
                    LayoutCell::idFor($r, $c),
                    $r,
                    $c,
                    [[$x0, $y0], [$x1, $y0], [$x1, $y1], [$x0, $y1]],
                    [["h{$r}_{$c}", false], ['v' . $r . '_' . ($c + 1), false], ['h' . ($r + 1) . '_' . $c, true], ["v{$r}_{$c}", true]],
                    $grid->cell($r, $c),
                );
            }
        }

        return new Layout('rect', $grid->width, $grid->height, $cells, $edges);
    }
}
