<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Topology;

use ImagePuzzle\Geometry\Bounds;

/**
 * Builds a Layout from clockwise cell polygons whose shared vertices have
 * bit-identical coordinates. Shared boundaries are discovered by matching
 * vertex pairs; unmatched segments must lie on the board rectangle and become
 * flat external edges. Cells of a convex tiling share at most one boundary, so
 * the edge key "<cellA>|<cellB>" (sorted ids) is stable and deterministic.
 */
final class PolygonLayoutBuilder
{
    /**
     * @param list<array{id: string, row: int, col: int, polygon: list<array{0: float, 1: float}>}> $cells
     * @param float $scaleFactor tab scale as a fraction of the boundary length
     */
    public static function build(string $topology, int $width, int $height, array $cells, float $scaleFactor): Layout
    {
        /** @var array<string, array{key: string, a: array{0: float, 1: float}, b: array{0: float, 1: float}, forward: string, reverse: ?string}> $byVertices */
        $byVertices = [];
        $cellEdges = [];
        $minDims = [];

        foreach ($cells as $cell) {
            $polygon = $cell['polygon'];
            $n = count($polygon);
            $minDims[$cell['id']] = self::minDimension($polygon);
            $cellEdges[$cell['id']] = [];
            for ($i = 0; $i < $n; $i++) {
                $p = $polygon[$i];
                $q = $polygon[($i + 1) % $n];
                $forwardKey = self::vertexKey($p) . '>' . self::vertexKey($q);
                $reverseKey = self::vertexKey($q) . '>' . self::vertexKey($p);
                if (isset($byVertices[$reverseKey])) {
                    if ($byVertices[$reverseKey]['reverse'] !== null) {
                        throw new \LogicException("Boundary {$reverseKey} is shared by more than two cells.");
                    }
                    $byVertices[$reverseKey]['reverse'] = $cell['id'];
                    $cellEdges[$cell['id']][] = $reverseKey;
                    continue;
                }
                $byVertices[$forwardKey] = ['key' => '', 'a' => $p, 'b' => $q, 'forward' => $cell['id'], 'reverse' => null];
                $cellEdges[$cell['id']][] = $forwardKey;
            }
        }

        $edges = [];
        $keysByVertices = [];
        $externalCounter = [];
        foreach ($byVertices as $vertexKey => $edge) {
            if ($edge['reverse'] === null) {
                self::assertOnBoard($edge['a'], $edge['b'], $width, $height);
                $index = $externalCounter[$edge['forward']] = ($externalCounter[$edge['forward']] ?? -1) + 1;
                $key = $edge['forward'] . '|x' . $index;
            } else {
                $pair = [$edge['forward'], $edge['reverse']];
                sort($pair);
                $key = $pair[0] . '|' . $pair[1];
            }
            $length = hypot($edge['b'][0] - $edge['a'][0], $edge['b'][1] - $edge['a'][1]);
            $scale = min($length * $scaleFactor, $minDims[$edge['forward']], $edge['reverse'] !== null ? $minDims[$edge['reverse']] : INF);
            $edges[$key] = new LayoutEdge($key, $edge['a'], $edge['b'], $edge['forward'], $edge['reverse'], self::orientation($edge['a'], $edge['b']), $scale);
            $keysByVertices[$vertexKey] = $key;
        }

        $layoutCells = [];
        foreach ($cells as $cell) {
            $refs = [];
            foreach ($cellEdges[$cell['id']] as $vertexKey) {
                $key = $keysByVertices[$vertexKey];
                $refs[] = [$key, $edges[$key]->forwardCell !== $cell['id']];
            }
            $layoutCells[] = new LayoutCell($cell['id'], $cell['row'], $cell['col'], $cell['polygon'], $refs, self::bounds($cell['polygon'], $width, $height));
        }

        return new Layout($topology, $width, $height, $layoutCells, $edges);
    }

    /**
     * Clip a convex clockwise polygon to [0, W] x [0, H]. Intersections are
     * computed from canonically ordered segment endpoints so that two cells
     * sharing a segment obtain bit-identical clipped vertices.
     *
     * @param list<array{0: float, 1: float}> $polygon
     * @return list<array{0: float, 1: float}>
     */
    public static function clip(array $polygon, float $width, float $height): array
    {
        $planes = [
            static fn (array $p): bool => $p[0] >= 0.0,
            static fn (array $p): bool => $p[0] <= $width,
            static fn (array $p): bool => $p[1] >= 0.0,
            static fn (array $p): bool => $p[1] <= $height,
        ];
        $lines = [[0, 0.0], [0, $width], [1, 0.0], [1, $height]];
        foreach ($planes as $k => $inside) {
            $output = [];
            $n = count($polygon);
            for ($i = 0; $i < $n; $i++) {
                $s = $polygon[$i];
                $e = $polygon[($i + 1) % $n];
                $sIn = $inside($s);
                $eIn = $inside($e);
                if ($sIn) {
                    $output[] = $s;
                }
                if ($sIn !== $eIn) {
                    $output[] = self::intersect($s, $e, $lines[$k][0], $lines[$k][1]);
                }
            }
            $polygon = self::dedupe($output);
            if (count($polygon) < 3) {
                return [];
            }
        }

        return $polygon;
    }

    /**
     * @param array{0: float, 1: float} $s
     * @param array{0: float, 1: float} $e
     * @return array{0: float, 1: float}
     */
    private static function intersect(array $s, array $e, int $axis, float $value): array
    {
        // Canonical order: identical result regardless of traversal direction.
        if ($s[0] > $e[0] || ($s[0] === $e[0] && $s[1] > $e[1])) {
            [$s, $e] = [$e, $s];
        }
        $t = ($value - $s[$axis]) / ($e[$axis] - $s[$axis]);
        $other = 1 - $axis;
        $coordinate = $s[$other] + $t * ($e[$other] - $s[$other]);

        return $axis === 0 ? [$value, $coordinate] : [$coordinate, $value];
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon
     * @return list<array{0: float, 1: float}>
     */
    private static function dedupe(array $polygon): array
    {
        $out = [];
        foreach ($polygon as $p) {
            $last = $out[count($out) - 1] ?? null;
            if ($last === null || $last[0] !== $p[0] || $last[1] !== $p[1]) {
                $out[] = $p;
            }
        }
        while (count($out) > 1 && $out[0][0] === $out[count($out) - 1][0] && $out[0][1] === $out[count($out) - 1][1]) {
            array_pop($out);
        }

        return $out;
    }

    /**
     * @param array{0: float, 1: float} $p
     */
    private static function vertexKey(array $p): string
    {
        // Exact binary representation: shared vertices must be bit-identical.
        return bin2hex(pack('E', $p[0])) . ':' . bin2hex(pack('E', $p[1]));
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private static function assertOnBoard(array $a, array $b, int $width, int $height): void
    {
        $onLine = ($a[0] === 0.0 && $b[0] === 0.0) || ($a[1] === 0.0 && $b[1] === 0.0)
            || ($a[0] === (float) $width && $b[0] === (float) $width) || ($a[1] === (float) $height && $b[1] === (float) $height);
        if (!$onLine) {
            throw new \LogicException('Unmatched boundary is not on the board perimeter: the tiling has a gap or T-junction.');
        }
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     */
    private static function orientation(array $a, array $b): string
    {
        return match (true) {
            $a[1] === $b[1] => 'horizontal',
            $a[0] === $b[0] => 'vertical',
            default => 'diagonal',
        };
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon
     */
    private static function minDimension(array $polygon): float
    {
        [$minX, $minY, $maxX, $maxY] = self::extent($polygon);

        return min($maxX - $minX, $maxY - $minY);
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon
     * @return array{0: float, 1: float, 2: float, 3: float} minX, minY, maxX, maxY
     */
    private static function extent(array $polygon): array
    {
        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach ($polygon as [$x, $y]) {
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }

        return [$minX, $minY, $maxX, $maxY];
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon
     */
    private static function bounds(array $polygon, int $width, int $height): Bounds
    {
        [$minX, $minY, $maxX, $maxY] = self::extent($polygon);
        $x0 = max(0, (int) floor($minX));
        $y0 = max(0, (int) floor($minY));
        $x1 = min($width, (int) ceil($maxX));
        $y1 = min($height, (int) ceil($maxY));

        return new Bounds($x0, $y0, max(1, $x1 - $x0), max(1, $y1 - $y0));
    }
}
