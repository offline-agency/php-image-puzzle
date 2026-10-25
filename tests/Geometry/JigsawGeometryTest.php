<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\ArcStyle;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\HalfCircleStyle;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\EdgeStyle\SinusStyle;
use ImagePuzzle\GeometryOptions;
use ImagePuzzle\Puzzle;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * Independent geometry oracles for G05-G07. Coverage is checked with a
 * winding-number test, which shares no code with the scanline rasterizer used
 * to render pieces.
 */

/**
 * @param list<array{0: float, 1: float}> $polygon
 */
function windingNumber(array $polygon, float $px, float $py): int
{
    $wn = 0;
    $n = count($polygon);
    for ($i = 0; $i < $n; $i++) {
        [$x0, $y0] = $polygon[$i];
        [$x1, $y1] = $polygon[($i + 1) % $n];
        $cross = ($x1 - $x0) * ($py - $y0) - ($px - $x0) * ($y1 - $y0);
        if ($y0 <= $py) {
            if ($y1 > $py && $cross > 0) {
                $wn++;
            }
        } elseif ($y1 <= $py && $cross < 0) {
            $wn--;
        }
    }

    return $wn;
}

/**
 * @return list<\ImagePuzzle\Piece>
 */
function piecesOf(Puzzle $puzzle): array
{
    return iterator_to_array($puzzle->getPieces(), false);
}

function styles(): array
{
    return [
        'jigsaw' => [new JigsawStyle()],
        'arc' => [new ArcStyle()],
        'half_circle' => [new HalfCircleStyle()],
        'sinus' => [new SinusStyle()],
    ];
}

test('every pixel centre belongs to exactly one piece (winding-number oracle)', function (EdgeStyleInterface $style, int $rows, int $cols, int $w, int $h, int $seed): void {
    $puzzle = (new PuzzleGenerator($style, geometry: new GeometryOptions($seed)))->generateFromGd(imagecreatetruecolor($w, $h), $rows, $cols);
    $pieces = piecesOf($puzzle);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $owners = 0;
            foreach ($pieces as $piece) {
                $owners += windingNumber($piece->getShape()->polygon, $x + 0.5, $y + 0.5) !== 0 ? 1 : 0;
            }
            expect($owners)->toBe(1, "pixel {$x},{$y}");
        }
    }
})->with(styles())->with([
    '3x4' => [3, 4, 61, 47, 1],
    '1x1' => [1, 1, 20, 15, 2],
    '1xN' => [1, 5, 53, 12, 3],
    'Nx1' => [4, 1, 13, 41, 4],
    'tiny cells' => [5, 5, 16, 17, 5],
]);

test('outlines are simple polygons (no self-intersection)', function (EdgeStyleInterface $style): void {
    $puzzle = (new PuzzleGenerator($style, geometry: new GeometryOptions(11)))->generateFromGd(imagecreatetruecolor(90, 70), 3, 3);
    foreach (piecesOf($puzzle) as $piece) {
        $p = $piece->getShape()->polygon;
        $n = count($p);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 2; $j < $n; $j++) {
                if ($i === 0 && $j === $n - 1) {
                    continue;
                }
                [$a, $b] = [$p[$i], $p[($i + 1) % $n]];
                [$c, $d] = [$p[$j], $p[($j + 1) % $n]];
                $o1 = ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
                $o2 = ($b[0] - $a[0]) * ($d[1] - $a[1]) - ($b[1] - $a[1]) * ($d[0] - $a[0]);
                $o3 = ($d[0] - $c[0]) * ($a[1] - $c[1]) - ($d[1] - $c[1]) * ($a[0] - $c[0]);
                $o4 = ($d[0] - $c[0]) * ($b[1] - $c[1]) - ($d[1] - $c[1]) * ($b[0] - $c[0]);
                expect($o1 * $o2 < 0 && $o3 * $o4 < 0)->toBeFalse("{$piece->getId()} segments {$i} and {$j} cross");
            }
        }
    }
})->with(styles());

test('areas sum to the board and tabs protrude while sockets indent', function (EdgeStyleInterface $style): void {
    $w = 120;
    $h = 90;
    $puzzle = (new PuzzleGenerator($style))->generateFromGd(imagecreatetruecolor($w, $h), 3, 4);
    $total = 0.0;
    $protruding = 0;
    $smallerThanCell = 0;
    foreach (piecesOf($puzzle) as $piece) {
        $area = $piece->getShape()->area();
        $total += $area;
        // A socket is an outline point well inside the logical cell.
        foreach ($piece->getShape()->polygon as [$x, $y]) {
            $depth = min($x - $piece->getX(), $piece->getX() + $piece->getWidth() - $x, $y - $piece->getY(), $piece->getY() + $piece->getHeight() - $y);
            if ($depth > 2.0) {
                $smallerThanCell++;
                break;
            }
        }
        $r = $piece->getRenderedBounds();
        $protruding += ($r->x < $piece->getX() || $r->y < $piece->getY() || $r->x + $r->width > $piece->getX() + $piece->getWidth() || $r->y + $r->height > $piece->getY() + $piece->getHeight()) ? 1 : 0;
    }
    expect(abs($total - $w * $h))->toBeLessThan(1e-6);
    expect($protruding)->toBeGreaterThan(0);
    expect($smallerThanCell)->toBeGreaterThan(0);
})->with(styles());

test('shared boundaries are exact complements and the board perimeter is flat', function (EdgeStyleInterface $style): void {
    $w = 100;
    $h = 80;
    $gen = new PuzzleGenerator($style, geometry: new GeometryOptions(3));
    $puzzle = $gen->generateFromGd(imagecreatetruecolor($w, $h), 2, 3);
    $layout = $gen->layout($w, $h, 2, 3);
    $builder = new \ImagePuzzle\Geometry\ShapeBuilder($layout, $style instanceof \ImagePuzzle\Geometry\BoundaryProfileProvider ? $style : new NoEdgeStyle(), 3);

    foreach ($layout->edges as $edge) {
        $points = $builder->edgeWorldPoints($edge);
        if ($edge->isExternal()) {
            expect($points)->toHaveCount(2);
            continue;
        }
        expect(count($points))->toBeGreaterThan(5);
        // The forward cell walks the points forwards, the reverse cell backwards.
        $forward = implode(';', array_map(static fn (array $p): string => $p[0] . ',' . $p[1], $points));
        $backward = implode(';', array_map(static fn (array $p): string => $p[0] . ',' . $p[1], array_reverse($points)));
        $f = $puzzle->getPiece($layout->cell((string) $edge->forwardCell)->row, $layout->cell((string) $edge->forwardCell)->col)->getShape()->polygon;
        $r = $puzzle->getPiece($layout->cell((string) $edge->reverseCell)->row, $layout->cell((string) $edge->reverseCell)->col)->getShape()->polygon;
        $fs = implode(';', array_map(static fn (array $p): string => $p[0] . ',' . $p[1], array_merge($f, $f)));
        $rs = implode(';', array_map(static fn (array $p): string => $p[0] . ',' . $p[1], array_merge($r, $r)));
        expect(str_contains($fs, $forward))->toBeTrue("forward {$edge->key}");
        expect(str_contains($rs, $backward))->toBeTrue("reverse {$edge->key}");
    }

    foreach (piecesOf($puzzle) as $piece) {
        foreach ($piece->getShape()->polygon as [$x, $y]) {
            expect($x)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual((float) $w);
            expect($y)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual((float) $h);
        }
    }
})->with(styles());

test('a single-piece board is the untouched rectangle', function (EdgeStyleInterface $style): void {
    $puzzle = (new PuzzleGenerator($style))->generateFromGd(imagecreatetruecolor(30, 20), 1, 1);
    expect($puzzle->getPiece(0, 0)->getShape()->polygon)->toBe([[0.0, 0.0], [30.0, 0.0], [30.0, 20.0], [0.0, 20.0]]);
})->with(styles());

test('exact mode reconstructs every source pixel bit-exactly with exactly one owner', function (EdgeStyleInterface $style): void {
    $w = 73;
    $h = 58;
    $source = Fixtures::coordinateImage($w, $h, true);
    $puzzle = (new PuzzleGenerator($style, geometry: GeometryOptions::exact(9)))->generateFromGd($source, 3, 4);
    $owner = [];
    foreach (piecesOf($puzzle) as $piece) {
        $img = $piece->toGdImage();
        $b = $piece->getRenderedBounds();
        expect([imagesx($img), imagesy($img)])->toBe([$b->width, $b->height]);
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                if ((($rgba >> 24) & 0x7F) === 127) {
                    continue;
                }
                $key = ($b->x + $x) . ',' . ($b->y + $y);
                expect(isset($owner[$key]))->toBeFalse("pixel {$key} owned twice");
                $owner[$key] = $rgba;
            }
        }
    }
    expect($owner)->toHaveCount($w * $h);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            expect($owner["{$x},{$y}"])->toBe(imagecolorat($source, $x, $y), "pixel {$x},{$y}");
        }
    }
})->with(styles());

test('antialiased mode stays within the documented additive alpha budget', function (EdgeStyleInterface $style): void {
    $w = 64;
    $h = 48;
    $source = Fixtures::coordinateImage($w, $h, true);
    $puzzle = (new PuzzleGenerator($style, geometry: new GeometryOptions(5, 4)))->generateFromGd($source, 2, 3);
    $opacity = [];
    $partial = [];
    foreach (piecesOf($puzzle) as $piece) {
        $img = $piece->toGdImage();
        $b = $piece->getRenderedBounds();
        for ($y = 0; $y < $b->height; $y++) {
            for ($x = 0; $x < $b->width; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                if ($a === 127) {
                    continue;
                }
                $sx = $b->x + $x;
                $sy = $b->y + $y;
                $src = imagecolorat($source, $sx, $sy);
                expect($rgba & 0xFFFFFF)->toBe($src & 0xFFFFFF);
                $key = "{$sx},{$sy}";
                $opacity[$key] = ($opacity[$key] ?? 0) + (127 - $a);
                if ($a !== (($src >> 24) & 0x7F)) {
                    $partial[$key] = ($partial[$key] ?? 0) + 1;
                }
            }
        }
    }
    $edgePixels = 0;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $key = "{$x},{$y}";
            $expected = 127 - ((imagecolorat($source, $x, $y) >> 24) & 0x7F);
            $budget = intdiv(($partial[$key] ?? 0) + 1, 2);
            expect(abs(($opacity[$key] ?? 0) - $expected))->toBeLessThanOrEqual($budget, "pixel {$key}");
            $edgePixels += isset($partial[$key]) ? 1 : 0;
        }
    }
    // Antialiasing really happened along curved boundaries.
    expect($edgePixels)->toBeGreaterThan(20);
})->with(styles());

test('outlines are deterministic and independent of piece order and subsets', function (EdgeStyleInterface $style): void {
    $image = imagecreatetruecolor(100, 100);
    $gen = new PuzzleGenerator($style, geometry: new GeometryOptions(42));
    $full = [];
    foreach (piecesOf($gen->generateFromGd($image, 4, 4)) as $piece) {
        $full[$piece->getId()] = $piece->getShape()->svgPath(6);
    }
    $subset = $gen->generatePiecesFromGd($image, 4, 4, [[3, 3], [0, 0], [2, 1]]);
    foreach ($subset as $piece) {
        expect($piece->getShape()->svgPath(6))->toBe($full[$piece->getId()]);
    }
    $again = piecesOf((new PuzzleGenerator($style, geometry: new GeometryOptions(42)))->generateFromGd($image, 4, 4));
    expect($again[5]->getShape()->svgPath(6))->toBe($full[$again[5]->getId()]);
    $other = piecesOf((new PuzzleGenerator($style, geometry: new GeometryOptions(43)))->generateFromGd($image, 4, 4));
    $differences = 0;
    foreach ($other as $piece) {
        $differences += $piece->getShape()->svgPath(6) !== $full[$piece->getId()] ? 1 : 0;
    }
    expect($differences)->toBeGreaterThan(0);
})->with(styles());

test('unequal last cells from a custom split stay a valid partition', function (): void {
    $split = new class implements DimensionSplitStrategy {
        public function split(int $totalSize, int $segmentCount): array
        {
            $first = intdiv($totalSize, $segmentCount) - 3;
            $rest = array_fill(0, $segmentCount - 1, $first);

            return [...$rest, $totalSize - $first * ($segmentCount - 1)];
        }
    };
    $w = 50;
    $h = 40;
    $puzzle = (new PuzzleGenerator(new JigsawStyle(), splitStrategy: $split))->generateFromGd(imagecreatetruecolor($w, $h), 3, 3);
    $pieces = piecesOf($puzzle);
    expect($puzzle->getPiece(0, 2)->getWidth())->toBeGreaterThan($puzzle->getPiece(0, 0)->getWidth());
    for ($y = 0; $y < $h; $y += 3) {
        for ($x = 0; $x < $w; $x += 3) {
            $owners = 0;
            foreach ($pieces as $piece) {
                $owners += windingNumber($piece->getShape()->polygon, $x + 0.5, $y + 0.5) !== 0 ? 1 : 0;
            }
            expect($owners)->toBe(1);
        }
    }
});

test('rectangular styles keep cell-sized pieces and pixel-identical output', function (): void {
    $source = Fixtures::coordinateImage(40, 30, true);
    $puzzle = (new PuzzleGenerator(new NoEdgeStyle()))->generateFromGd($source, 2, 2);
    $piece = $puzzle->getPiece(1, 1);
    expect($piece->getRenderedBounds()->toArray())->toBe(['x' => 20, 'y' => 15, 'width' => 20, 'height' => 15]);
    $img = $piece->toGdImage();
    expect(imagecolorat($img, 3, 4))->toBe(imagecolorat($source, 23, 19));
});
