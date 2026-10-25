<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry;

/**
 * Closed outline of one piece in world (source pixel) coordinates.
 *
 * $logical is the cell the piece belongs to; $rendered is the integer
 * bounding box of the outline clipped to the source, i.e. the size and
 * placement offset of the rendered piece image. For a protruding tab the
 * rendered box is larger than the logical cell.
 */
final readonly class PieceShape
{
    /**
     * @param list<array{0: float, 1: float}> $polygon clockwise outline, not repeating the first point
     */
    public function __construct(
        public string $id,
        public int $row,
        public int $col,
        public Bounds $logical,
        public Bounds $rendered,
        public array $polygon,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon
     */
    public static function renderedBounds(array $polygon, int $sourceWidth, int $sourceHeight): Bounds
    {
        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach ($polygon as [$x, $y]) {
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }
        $x0 = max(0, (int) floor($minX));
        $y0 = max(0, (int) floor($minY));
        $x1 = min($sourceWidth, (int) ceil($maxX));
        $y1 = min($sourceHeight, (int) ceil($maxY));

        return new Bounds($x0, $y0, max(1, $x1 - $x0), max(1, $y1 - $y0));
    }

    /**
     * SVG path data in world coordinates ("M x y L ... Z").
     */
    public function svgPath(int $precision = 2, float $offsetX = 0.0, float $offsetY = 0.0): string
    {
        $parts = [];
        foreach ($this->polygon as $i => [$x, $y]) {
            $parts[] = ($i === 0 ? 'M' : 'L') . self::num($x - $offsetX, $precision) . ' ' . self::num($y - $offsetY, $precision);
        }

        return implode(' ', $parts) . ' Z';
    }

    /**
     * Signed area (positive for clockwise outlines in screen coordinates).
     */
    public function area(): float
    {
        $sum = 0.0;
        $n = count($this->polygon);
        for ($i = 0; $i < $n; $i++) {
            [$x0, $y0] = $this->polygon[$i];
            [$x1, $y1] = $this->polygon[($i + 1) % $n];
            $sum += $x0 * $y1 - $x1 * $y0;
        }

        return $sum / 2.0;
    }

    private static function num(float $value, int $precision): string
    {
        $formatted = rtrim(rtrim(number_format($value, $precision, '.', ''), '0'), '.');

        return $formatted === '-0' || $formatted === '' ? '0' : $formatted;
    }
}
