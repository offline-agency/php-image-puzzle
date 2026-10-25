<?php

declare(strict_types=1);

namespace ImagePuzzle\Game\Tangram;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Geometry of the standard seven-piece tangram and its target arrangements.
 *
 * Coordinate convention: a square of side 4 units, x to the right, y down
 * (image coordinates), origin at the top-left corner of the square. The
 * partition (vertices in units, in tile order):
 *
 *     large-a        (0,0) (4,0) (2,2)          top large triangle
 *     large-b        (0,0) (2,2) (0,4)          left large triangle
 *     medium         (4,2) (4,4) (2,4)          bottom-right triangle
 *     small-a        (4,0) (4,2) (3,1)          right small triangle
 *     small-b        (2,2) (3,3) (1,3)          centre small triangle
 *     square         (2,2) (3,1) (4,2) (3,3)
 *     parallelogram  (0,4) (1,3) (3,3) (2,4)
 *
 * Areas: 4 + 4 + 2 + 1 + 1 + 2 + 2 = 16 square units.
 *
 * A pose places a piece: its centroid (vertex mean) at integer world pixels
 * (x, y), then "flipped" mirrors it across the vertical axis through the
 * centroid, then "rotation" turns it clockwise by rotation x 45 degrees.
 * Rotations use exact cosine/sine values from a table (1, 0, and
 * M_SQRT1_2), never trigonometric functions, and every operation happens in
 * the same order in PHP and TypeScript, so both compute identical doubles.
 *
 * With a unit of u pixels (u a multiple of 6), every vertex of the solved
 * square and every centroid is an integer pixel.
 */
final class Tangram
{
    /** Distance under which two vertices are the same point, in pixels. */
    public const EPSILON = 1e-6;

    /** @var array<string, array{shape: string, vertices: list<array{0: int, 1: int}>}> */
    public const PIECES = [
        'large-a' => ['shape' => 'large', 'vertices' => [[0, 0], [4, 0], [2, 2]]],
        'large-b' => ['shape' => 'large', 'vertices' => [[0, 0], [2, 2], [0, 4]]],
        'medium' => ['shape' => 'medium', 'vertices' => [[4, 2], [4, 4], [2, 4]]],
        'small-a' => ['shape' => 'small', 'vertices' => [[4, 0], [4, 2], [3, 1]]],
        'small-b' => ['shape' => 'small', 'vertices' => [[2, 2], [3, 3], [1, 3]]],
        'square' => ['shape' => 'square', 'vertices' => [[2, 2], [3, 1], [4, 2], [3, 3]]],
        'parallelogram' => ['shape' => 'parallelogram', 'vertices' => [[0, 4], [1, 3], [3, 3], [2, 4]]],
    ];

    /**
     * Supported targets. Each slot is a piece moved by (dx, dy) units from
     * its solved position. "picture" targets bind every slot to its own
     * piece in its solved orientation, so the image is reassembled;
     * silhouettes accept any piece of the slot's shape in any orientation.
     *
     * - square: the solved picture.
     * - arrow: large-b moved to the right side, an arrow pointing right.
     * - chevron: large-a moved below the square, an arrow pointing down.
     *
     * @var array<string, array{picture: bool, moves: array<string, array{0: int, 1: int}>}>
     */
    public const TARGETS = [
        'square' => ['picture' => true, 'moves' => []],
        'arrow' => ['picture' => false, 'moves' => ['large-b' => [4, 0]]],
        'chevron' => ['picture' => false, 'moves' => ['large-a' => [0, 4]]],
    ];

    /** cos and sin of k x 45 degrees, clockwise with y down. */
    private const TURNS = [[1.0, 0.0], [M_SQRT1_2, M_SQRT1_2], [0.0, 1.0], [-M_SQRT1_2, M_SQRT1_2], [-1.0, 0.0], [-M_SQRT1_2, -M_SQRT1_2], [0.0, -1.0], [M_SQRT1_2, -M_SQRT1_2]];

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::PIECES);
    }

    public static function target(string $name): void
    {
        if (!isset(self::TARGETS[$name])) {
            throw new InvalidArgumentException("Unknown tangram target \"{$name}\"; supported targets: " . implode(', ', array_keys(self::TARGETS)) . '. Other silhouettes are not recognised.');
        }
    }

    /**
     * Solved vertices of a piece in world pixels.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function home(string $id, int $originX, int $originY, int $unit): array
    {
        return array_map(static fn (array $v): array => [$originX + $v[0] * $unit, $originY + $v[1] * $unit], self::PIECES[$id]['vertices']);
    }

    /**
     * Bounding box of the solved piece in world pixels.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} x, y, width, height
     */
    public static function homeBox(string $id, int $originX, int $originY, int $unit): array
    {
        $minX = $minY = PHP_INT_MAX;
        $maxX = $maxY = PHP_INT_MIN;
        foreach (self::home($id, $originX, $originY, $unit) as [$x, $y]) {
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }

        return [$minX, $minY, $maxX - $minX, $maxY - $minY];
    }

    /**
     * Centroid (vertex mean) of the solved piece: integer when $unit is a multiple of 6.
     *
     * @return array{0: int, 1: int}
     */
    public static function homeCentroid(string $id, int $originX, int $originY, int $unit): array
    {
        $vertices = self::PIECES[$id]['vertices'];
        $n = count($vertices);
        $sx = array_sum(array_column($vertices, 0)) * $unit;
        $sy = array_sum(array_column($vertices, 1)) * $unit;

        return [$originX + intdiv($sx, $n), $originY + intdiv($sy, $n)];
    }

    /**
     * World vertices of a piece in a pose.
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function polygon(string $id, int $unit, int $x, int $y, int $rotation, bool $flipped): array
    {
        [$cos, $sin] = self::TURNS[$rotation];
        $vertices = self::PIECES[$id]['vertices'];
        $n = count($vertices);
        // Local coordinates relative to the centroid, in pixels (exact: multiples of u/3 or u/2).
        $cx = array_sum(array_column($vertices, 0)) * $unit / $n;
        $cy = array_sum(array_column($vertices, 1)) * $unit / $n;
        $out = [];
        foreach ($vertices as [$vx, $vy]) {
            $lx = $vx * $unit - $cx;
            $ly = $vy * $unit - $cy;
            if ($flipped) {
                $lx = -$lx;
            }
            $out[] = [$x + ($lx * $cos - $ly * $sin), $y + ($lx * $sin + $ly * $cos)];
        }

        return $out;
    }

    /**
     * Slots of a target in world pixels: the defining piece, its shape,
     * whether only that piece in that pose fits (picture targets) and its pose.
     *
     * @return list<array{piece: string, shape: string, bound: bool, x: int, y: int, rotation: int, flipped: bool}>
     */
    public static function slots(string $target, int $originX, int $originY, int $unit): array
    {
        self::target($target);
        $definition = self::TARGETS[$target];
        $slots = [];
        foreach (self::PIECES as $id => $piece) {
            [$dx, $dy] = $definition['moves'][$id] ?? [0, 0];
            [$cx, $cy] = self::homeCentroid($id, $originX, $originY, $unit);
            $slots[] = ['piece' => $id, 'shape' => $piece['shape'], 'bound' => $definition['picture'], 'x' => $cx + $dx * $unit, 'y' => $cy + $dy * $unit, 'rotation' => 0, 'flipped' => false];
        }

        return $slots;
    }

    /**
     * Bounding box of a target in units relative to the square's origin.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} minX, minY, maxX, maxY
     */
    public static function extent(string $target): array
    {
        self::target($target);
        $minX = $minY = 0;
        $maxX = $maxY = 4;
        foreach (self::TARGETS[$target]['moves'] as $id => [$dx, $dy]) {
            foreach (self::PIECES[$id]['vertices'] as [$vx, $vy]) {
                $minX = min($minX, $vx + $dx);
                $minY = min($minY, $vy + $dy);
                $maxX = max($maxX, $vx + $dx);
                $maxY = max($maxY, $vy + $dy);
            }
        }

        return [$minX, $minY, $maxX, $maxY];
    }

    /**
     * Largest vertex distance between two polygons as point sets: the
     * furthest any vertex of $a lies from its nearest vertex of $b, and back.
     *
     * @param list<array{0: float, 1: float}> $a
     * @param list<array{0: float, 1: float}> $b
     */
    public static function distance(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            return INF;
        }

        return max(self::directed($a, $b), self::directed($b, $a));
    }

    /**
     * Pose that puts piece $id exactly on $slot, or null when the shapes differ.
     * Silhouette slots accept any orientation: $prefer's orientation when it
     * fits (so a snapped piece keeps its side up), else unflipped first and
     * rotations in order.
     *
     * @param array{piece: string, shape: string, bound: bool, x: int, y: int, rotation: int, flipped: bool} $slot
     * @param array{x: int, y: int, rotation: int, flipped: bool}|null $prefer
     * @return array{x: int, y: int, rotation: int, flipped: bool}|null
     */
    public static function fit(string $id, array $slot, int $unit, ?array $prefer = null): ?array
    {
        if (self::PIECES[$id]['shape'] !== $slot['shape'] || ($slot['bound'] && $slot['piece'] !== $id)) {
            return null;
        }
        if ($slot['bound']) {
            return ['x' => $slot['x'], 'y' => $slot['y'], 'rotation' => $slot['rotation'], 'flipped' => $slot['flipped']];
        }
        $target = self::polygon($slot['piece'], $unit, $slot['x'], $slot['y'], $slot['rotation'], $slot['flipped']);
        if ($prefer !== null && self::distance(self::polygon($id, $unit, $slot['x'], $slot['y'], $prefer['rotation'], $prefer['flipped']), $target) <= self::EPSILON) {
            return ['x' => $slot['x'], 'y' => $slot['y'], 'rotation' => $prefer['rotation'], 'flipped' => $prefer['flipped']];
        }
        foreach ([false, true] as $flipped) {
            for ($rotation = 0; $rotation < 8; $rotation++) {
                if (self::distance(self::polygon($id, $unit, $slot['x'], $slot['y'], $rotation, $flipped), $target) <= self::EPSILON) {
                    return ['x' => $slot['x'], 'y' => $slot['y'], 'rotation' => $rotation, 'flipped' => $flipped];
                }
            }
        }

        return null;
    }

    /**
     * Twice the signed area of a polygon (positive when clockwise with y down).
     *
     * @param list<array{0: float, 1: float}> $polygon
     */
    public static function doubleArea(array $polygon): float
    {
        $sum = 0.0;
        $n = count($polygon);
        for ($i = 0; $i < $n; $i++) {
            [$x1, $y1] = $polygon[$i];
            [$x2, $y2] = $polygon[($i + 1) % $n];
            $sum += $x1 * $y2 - $x2 * $y1;
        }

        return $sum;
    }

    /**
     * Whether two convex polygons overlap by more than $tolerance (separating
     * axis test; touching edges and vertices do not count as overlap).
     *
     * @param list<array{0: float, 1: float}> $a
     * @param list<array{0: float, 1: float}> $b
     */
    public static function overlap(array $a, array $b, float $tolerance): bool
    {
        foreach ([$a, $b] as $polygon) {
            $n = count($polygon);
            for ($i = 0; $i < $n; $i++) {
                [$x1, $y1] = $polygon[$i];
                [$x2, $y2] = $polygon[($i + 1) % $n];
                $length = hypot($x2 - $x1, $y2 - $y1);
                $nx = ($y1 - $y2) / $length;
                $ny = ($x2 - $x1) / $length;
                [$minA, $maxA] = self::project($a, $nx, $ny);
                [$minB, $maxB] = self::project($b, $nx, $ny);
                if (min($maxA, $maxB) - max($minA, $minB) <= $tolerance) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Checks that a target is a valid arrangement: the seven pieces, no
     * overlap beyond a 1e-6 unit tolerance and a total area of 16 square
     * units. Returns the problems found (empty when valid).
     *
     * @return list<string>
     */
    public static function validate(string $target, int $unit = 6): array
    {
        $slots = self::slots($target, 0, 0, $unit);
        $polygons = [];
        $problems = [];
        $area = 0.0;
        foreach ($slots as $slot) {
            $polygon = self::polygon($slot['piece'], $unit, $slot['x'], $slot['y'], $slot['rotation'], $slot['flipped']);
            $area += abs(self::doubleArea($polygon)) / 2;
            $polygons[$slot['piece']] = $polygon;
        }
        if (abs($area - 16 * $unit * $unit) > 1e-6 * $unit * $unit) {
            $problems[] = "area {$area} instead of " . (16 * $unit * $unit);
        }
        $ids = array_keys($polygons);
        foreach ($ids as $i => $first) {
            foreach (array_slice($ids, $i + 1) as $second) {
                if (self::overlap($polygons[$first], $polygons[$second], 1e-6 * $unit)) {
                    $problems[] = "{$first} overlaps {$second}";
                }
            }
        }

        return $problems;
    }

    /**
     * @param list<array{0: float, 1: float}> $polygon
     * @return array{0: float, 1: float}
     */
    private static function project(array $polygon, float $nx, float $ny): array
    {
        $min = INF;
        $max = -INF;
        foreach ($polygon as [$x, $y]) {
            $p = $x * $nx + $y * $ny;
            $min = min($min, $p);
            $max = max($max, $p);
        }

        return [$min, $max];
    }

    /**
     * @param list<array{0: float, 1: float}> $a
     * @param list<array{0: float, 1: float}> $b
     */
    private static function directed(array $a, array $b): float
    {
        $worst = 0.0;
        foreach ($a as [$ax, $ay]) {
            $best = INF;
            foreach ($b as [$bx, $by]) {
                $best = min($best, sqrt(($ax - $bx) * ($ax - $bx) + ($ay - $by) * ($ay - $by)));
            }
            $worst = max($worst, $best);
        }

        return $worst;
    }
}
