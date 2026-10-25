<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Scanline polygon coverage with an exact partition rule.
 *
 * Every pixel is split into samples x samples sub-samples located at
 * (px + (i + 0.5) / s, py + (j + 0.5) / s). A sub-sample belongs to a polygon
 * when an odd number of polygon edges cross its scanline strictly to its right,
 * with edges treated as half-open in y (y0 <= y < y1 after ordering the
 * endpoints). Crossings are always computed from the ordered endpoints, so two
 * polygons sharing the same boundary points compute identical crossings.
 * Consequently each sub-sample of a board belongs to exactly one piece and the
 * coverages of all pieces sum to samples^2 on every pixel.
 *
 * samples = 1 is the exact (non-antialiased) reference mode.
 */
final readonly class Rasterizer
{
    public function __construct(
        public int $samples = 4,
    ) {
        if ($samples < 1 || $samples > 16) {
            throw new InvalidArgumentException('Rasterizer samples must be between 1 and 16.');
        }
    }

    public function maxCoverage(): int
    {
        return $this->samples * $this->samples;
    }

    /**
     * Invoke $row(y, coverage) for every pixel row of $bounds, where coverage is
     * a list of integer coverages (0..maxCoverage) indexed from $bounds->x.
     *
     * @param list<array{0: float, 1: float}> $polygon
     * @param callable(int, list<int>): void $row
     */
    public function scan(array $polygon, Bounds $bounds, callable $row): void
    {
        $s = $this->samples;
        $w = $bounds->width;
        $bucketed = $this->bucketEdges($polygon, $bounds);
        $kMin = $bounds->x * $s;
        $kMax = ($bounds->x + $w) * $s;

        for ($ly = 0; $ly < $bounds->height; $ly++) {
            $py = $bounds->y + $ly;
            $direct = array_fill(0, $w, 0);
            $diff = array_fill(0, $w + 1, 0);
            $edges = $bucketed[$ly] ?? [];
            if ($edges !== []) {
                for ($j = 0; $j < $s; $j++) {
                    $sy = $py + ($j + 0.5) / $s;
                    $xs = [];
                    foreach ($edges as [$x0, $y0, $x1, $y1]) {
                        if ($sy >= $y0 && $sy < $y1) {
                            $xs[] = $x0 + ($sy - $y0) * ($x1 - $x0) / ($y1 - $y0);
                        }
                    }
                    $n = count($xs);
                    if ($n < 2) {
                        continue;
                    }
                    sort($xs);
                    for ($i = 0; $i + 1 < $n; $i += 2) {
                        $kStart = max($kMin, (int) ceil($xs[$i] * $s - 0.5));
                        $kEnd = min($kMax, (int) ceil($xs[$i + 1] * $s - 0.5));
                        if ($kStart >= $kEnd) {
                            continue;
                        }
                        $kStart -= $kMin;
                        $kEnd -= $kMin;
                        $p0 = intdiv($kStart, $s);
                        $p1 = intdiv($kEnd - 1, $s);
                        if ($p0 === $p1) {
                            $direct[$p0] += $kEnd - $kStart;
                            continue;
                        }
                        $direct[$p0] += ($p0 + 1) * $s - $kStart;
                        $direct[$p1] += $kEnd - $p1 * $s;
                        if ($p1 > $p0 + 1) {
                            $diff[$p0 + 1] += $s;
                            $diff[$p1] -= $s;
                        }
                    }
                }
            }
            $running = 0;
            for ($lx = 0; $lx < $w; $lx++) {
                $running += $diff[$lx];
                $direct[$lx] += $running;
            }
            $row($py, $direct);
        }
    }

    /**
     * Group non-horizontal edges by the local pixel rows they intersect.
     *
     * @param list<array{0: float, 1: float}> $polygon
     * @return array<int, list<array{0: float, 1: float, 2: float, 3: float}>>
     */
    private function bucketEdges(array $polygon, Bounds $bounds): array
    {
        $buckets = [];
        $n = count($polygon);
        for ($i = 0; $i < $n; $i++) {
            [$ax, $ay] = $polygon[$i];
            [$bx, $by] = $polygon[($i + 1) % $n];
            if ($ay === $by) {
                continue;
            }
            $edge = $ay < $by ? [$ax, $ay, $bx, $by] : [$bx, $by, $ax, $ay];
            $from = max(0, (int) floor($edge[1]) - $bounds->y);
            $to = min($bounds->height - 1, (int) ceil($edge[3]) - 1 - $bounds->y);
            for ($r = $from; $r <= $to; $r++) {
                $buckets[$r][] = $edge;
            }
        }

        return $buckets;
    }
}
