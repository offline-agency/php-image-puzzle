<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\ArcStyle;
use ImagePuzzle\EdgeStyle\CompositeEdgeStyle;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\HalfCircleStyle;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\EdgeStyle\SinusStyle;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\Geometry\BoundaryProfileProvider;
use ImagePuzzle\Geometry\ShapeBuilder;
use ImagePuzzle\Geometry\Topology\HexagonalTopology;
use ImagePuzzle\Geometry\Topology\TopologyInterface;
use ImagePuzzle\Geometry\Topology\TriangularTopology;
use ImagePuzzle\GeometryOptions;
use ImagePuzzle\Puzzle;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\ResourceLimits;
use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * Oracles for hexagonal and triangular boards (F23). Ownership uses an
 * even-odd ray test that shares no code with the scanline rasterizer.
 */

/**
 * @param list<array{0: float, 1: float}> $polygon
 */
function topologyContains(array $polygon, float $px, float $py): bool
{
    $inside = false;
    $n = count($polygon);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$xi, $yi] = $polygon[$i];
        [$xj, $yj] = $polygon[$j];
        if (($yi > $py) !== ($yj > $py) && $px < ($xj - $xi) * ($py - $yi) / ($yj - $yi) + $xi) {
            $inside = !$inside;
        }
    }

    return $inside;
}

/**
 * @return list<\ImagePuzzle\Piece>
 */
function topologyPieces(Puzzle $puzzle): array
{
    return iterator_to_array($puzzle->getPieces(), false);
}

/**
 * @return array<string, array{0: TopologyInterface, 1: EdgeStyleInterface}>
 */
function topologyCases(): array
{
    $cases = [];
    foreach (['hex' => new HexagonalTopology(), 'triangle' => new TriangularTopology()] as $t => $topology) {
        foreach (['flat' => new NoEdgeStyle(), 'jigsaw' => new JigsawStyle(), 'arc' => new ArcStyle(), 'half_circle' => new HalfCircleStyle(), 'sinus' => new SinusStyle()] as $s => $style) {
            $cases["{$t}/{$s}"] = [$topology, $style];
        }
    }

    return $cases;
}

/**
 * @param list<array{0: float, 1: float}> $points
 */
function joinPoints(array $points): string
{
    return implode(';', array_map(static fn (array $p): string => $p[0] . ',' . $p[1], $points));
}

test('every pixel centre belongs to exactly one piece', function (TopologyInterface $topology, EdgeStyleInterface $style, int $rows, int $cols, int $w, int $h): void {
    $puzzle = (new PuzzleGenerator($style, geometry: new GeometryOptions(7, 4, $topology)))->generateFromGd(Fixtures::canvas($w, $h), $rows, $cols);
    $pieces = topologyPieces($puzzle);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $owners = 0;
            foreach ($pieces as $piece) {
                $owners += topologyContains($piece->getShape()->polygon, $x + 0.5, $y + 0.5) ? 1 : 0;
            }
            expect($owners)->toBe(1, "pixel {$x},{$y}");
        }
    }
})->with(topologyCases())->with([
    '3x3 wide' => [3, 3, 97, 61],
    '4x2 tall' => [4, 2, 47, 83],
]);

test('cell counts follow the documented row structure', function (): void {
    $hex = (new HexagonalTopology())->layout(120, 90, 4, 3);
    expect($hex->cells)->toHaveCount(2 * 4 + 2 * 3);
    expect(array_count_values(array_map(static fn ($c): int => $c->row, array_values($hex->cells))))->toBe([0 => 4, 1 => 3, 2 => 4, 3 => 3]);

    $tri = (new TriangularTopology())->layout(120, 90, 3, 4);
    expect($tri->cells)->toHaveCount(3 * (2 * 4 + 1));
    foreach ($tri->cells as $cell) {
        expect($cell->corners)->toHaveCount(3);
    }
});

test('every internal boundary is shared by exactly two cells in opposite directions', function (TopologyInterface $topology): void {
    $layout = $topology->layout(150, 110, 4, 3);
    $uses = [];
    foreach ($layout->cells as $cell) {
        expect(count($cell->edges))->toBe(count($cell->corners));
        foreach ($cell->edges as [$key, $reversed]) {
            $uses[$key][] = $reversed;
        }
    }
    foreach ($layout->edges as $key => $edge) {
        if ($edge->isExternal()) {
            expect($uses[$key])->toBe([false]);
            $onPerimeter = ($edge->a[0] === $edge->b[0] && in_array($edge->a[0], [0.0, 150.0], true))
                || ($edge->a[1] === $edge->b[1] && in_array($edge->a[1], [0.0, 110.0], true));
            expect($onPerimeter)->toBeTrue("external edge {$key} lies on the board perimeter");
            continue;
        }
        expect($uses[$key])->toHaveCount(2);
        sort($uses[$key]);
        expect($uses[$key])->toBe([false, true]);
    }
})->with(['hex' => [new HexagonalTopology()], 'triangle' => [new TriangularTopology()]]);

test('shared boundaries are exact complements and outlines are simple', function (TopologyInterface $topology, EdgeStyleInterface $style): void {
    $gen = new PuzzleGenerator($style, geometry: new GeometryOptions(3, 4, $topology));
    $puzzle = $gen->generateFromGd(Fixtures::canvas(130, 100), 3, 3);
    $layout = $gen->layout(130, 100, 3, 3);
    $builder = new ShapeBuilder($layout, $style instanceof BoundaryProfileProvider ? $style : new NoEdgeStyle(), 3);
    $join = joinPoints(...);

    foreach ($layout->edges as $edge) {
        if ($edge->isExternal()) {
            expect($builder->edgeWorldPoints($edge))->toHaveCount(2);
            continue;
        }
        $points = $builder->edgeWorldPoints($edge);
        $f = $layout->cell((string) $edge->forwardCell);
        $r = $layout->cell((string) $edge->reverseCell);
        $fp = $puzzle->getPiece($f->row, $f->col)->getShape()->polygon;
        $rp = $puzzle->getPiece($r->row, $r->col)->getShape()->polygon;
        expect(str_contains($join(array_merge($fp, $fp)), $join($points)))->toBeTrue("forward {$edge->key}");
        expect(str_contains($join(array_merge($rp, $rp)), $join(array_reverse($points))))->toBeTrue("reverse {$edge->key}");
    }

    foreach (topologyPieces($puzzle) as $piece) {
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
})->with(topologyCases());

test('exact mode reconstructs every source pixel with exactly one owner', function (TopologyInterface $topology, EdgeStyleInterface $style): void {
    $w = 71;
    $h = 59;
    $source = Fixtures::coordinateImage($w, $h, true);
    $puzzle = (new PuzzleGenerator($style, geometry: GeometryOptions::exact(9, $topology)))->generateFromGd($source, 3, 3);
    $owner = [];
    foreach (topologyPieces($puzzle) as $piece) {
        $img = $piece->toGdImage();
        $b = $piece->getRenderedBounds();
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
})->with(topologyCases());

test('subsets and repeated runs give identical outlines; border flags follow the layout', function (TopologyInterface $topology): void {
    $image = Fixtures::canvas(120, 100);
    $gen = new PuzzleGenerator(new JigsawStyle(), geometry: new GeometryOptions(42, 4, $topology));
    $full = [];
    $borders = 0;
    foreach (topologyPieces($gen->generateFromGd($image, 4, 3)) as $piece) {
        $full[$piece->getId()] = $piece->getShape()->svgPath(6);
        $borders += $piece->getContext()->isBorder() ? 1 : 0;
    }
    expect($borders)->toBeGreaterThan(0)->toBeLessThan(count($full));
    foreach ($gen->generatePiecesFromGd($image, 4, 3, [[3, 2], [0, 0], [1, 1]]) as $piece) {
        expect($piece->getShape()->svgPath(6))->toBe($full[$piece->getId()]);
    }
    // Interior cells of a 4x3 hex board are not on the border.
    $interior = $gen->generatePiecesFromGd($image, 4, 3, [[1, 1]])[0];
    expect($interior->getContext()->isBorder())->toBeFalse();
})->with(['hex' => [new HexagonalTopology()], 'triangle' => [new TriangularTopology()]]);

test('indices outside a ragged row are rejected', function (): void {
    $gen = new PuzzleGenerator(new JigsawStyle(), geometry: new GeometryOptions(1, 4, new HexagonalTopology()));
    // Even rows hold cols + 1 cells, odd rows cols cells.
    expect($gen->generatePiecesFromGd(Fixtures::canvas(90, 90), 3, 3, [[0, 3]]))->toHaveCount(1);
    expect(fn () => $gen->generatePiecesFromGd(Fixtures::canvas(90, 90), 3, 3, [[1, 3]]))
        ->toThrow(InvalidArgumentException::class, 'not a cell of this hex board');
});

test('invalid board requests are rejected before rendering', function (): void {
    expect(fn () => (new HexagonalTopology())->layout(100, 100, 1, 3))->toThrow(InvalidArgumentException::class, 'at least 2 rows');
    expect(fn () => (new TriangularTopology())->layout(20, 20, 2, 9))->toThrow(InvalidArgumentException::class, 'too dense');
    $split = new class () implements DimensionSplitStrategy {
        public function split(int $totalSize, int $segmentCount): array
        {
            return array_fill(0, $segmentCount, intdiv($totalSize, $segmentCount));
        }
    };
    expect(fn () => (new HexagonalTopology())->layout(100, 100, 3, 3, $split))->toThrow(InvalidArgumentException::class, 'rectangular boards only');
    // Composite styles pick profiles by horizontal/vertical orientation only.
    $composite = new CompositeEdgeStyle(new JigsawStyle(), new ArcStyle(), new JigsawStyle(), new ArcStyle());
    expect(fn () => (new PuzzleGenerator($composite, geometry: new GeometryOptions(1, 4, new TriangularTopology())))->generateFromGd(Fixtures::canvas(60, 60), 2, 2))
        ->toThrow(InvalidArgumentException::class, 'rectangular boards only');
    // The piece budget counts real cells, not rows x cols.
    $limits = new ResourceLimits(maxPieces: 10);
    expect(fn () => (new PuzzleGenerator(new JigsawStyle(), limits: $limits, geometry: new GeometryOptions(1, 4, new TriangularTopology())))->generateFromGd(Fixtures::canvas(80, 80), 2, 3))
        ->toThrow(ResourceLimitExceededException::class, '14 pieces');
});

test('a hexagonal export records its topology in the manifest', function (): void {
    $dir = Fixtures::tempDir('hex-export');
    try {
        $source = $dir . '/source.png';
        Fixtures::writePng(Fixtures::coordinateImage(90, 70), $source);
        $result = PuzzleGenerator::generateAndExport($source, $dir . '/out', 3, 2, new JigsawStyle(), new ExportOptions(), geometry: new GeometryOptions(5, 2, new HexagonalTopology()));
        expect($result->getManifest())->toMatchArray(['geometry' => ['version' => 2, 'topology' => 'hex', 'style' => 'jigsaw', 'seed' => 5, 'samples' => 2, 'compositing' => 'additive']]);
        $pieces = Fixtures::pieces($result->getManifest());
        expect($pieces)->toHaveCount(3 + 2 + 3);
        foreach ($pieces as $piece) {
            expect(is_file($dir . '/out/' . $piece['filename']))->toBeTrue();
        }
    } finally {
        Fixtures::removeDir($dir);
    }
});
