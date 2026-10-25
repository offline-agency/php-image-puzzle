<?php

declare(strict_types=1);

use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\Export\PdfExporter;
use ImagePuzzle\Export\PdfOptions;
use ImagePuzzle\Export\SvgExporter;
use ImagePuzzle\Export\SvgOptions;
use ImagePuzzle\Game\CanonicalJson;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\PuzzleDirectory;
use ImagePuzzle\Game\Tile;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * SVG and PDF exports of a built puzzle. Both draw the outlines stored in
 * the definition, so they are checked against what the raster pieces and
 * the player use: the SVG is rendered with rsvg-convert and compared with
 * the source, outlines are compared with the piece alpha masks, and PDF
 * pages are inspected with pdfinfo/qpdf and rendered with pdftoppm.
 *
 * Renderer-based tests skip when the tools are missing, unless
 * REQUIRE_RENDERERS=1 (set in CI), where a missing tool is a failure.
 */

final class VectorWorkspaces
{
    /** @var list<string> */
    public static array $dirs = [];

    /** @var array<string, PuzzleDirectory> */
    public static array $built = [];
}

/**
 * Whether a test needing these tools must be skipped. With
 * REQUIRE_RENDERERS=1 nothing is skipped and vectorTool() fails instead.
 */
function missingTool(string ...$names): bool
{
    if (getenv('REQUIRE_RENDERERS') === '1') {
        return false;
    }
    foreach ($names as $name) {
        if (vectorToolPath($name) === null) {
            return true;
        }
    }

    return false;
}

function vectorToolPath(string $name): ?string
{
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        if ($dir !== '' && is_executable("{$dir}/{$name}")) {
            return "{$dir}/{$name}";
        }
    }

    return null;
}

function vectorTool(string $name): string
{
    return vectorToolPath($name) ?? throw new RuntimeException("{$name} is not installed (required with REQUIRE_RENDERERS=1).");
}

/**
 * Runs a tool without a shell.
 *
 * @param list<string> $command
 *
 * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
 */
function vectorRun(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if ($process === false) {
        throw new RuntimeException('Cannot start ' . $command[0]);
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out, $err];
}

function vectorDir(): string
{
    $dir = Fixtures::tempDir('ip-vector-');
    VectorWorkspaces::$dirs[] = $dir;

    return $dir;
}

/** A built puzzle from a 320x240 coordinate image, built once per kind. */
function vectorPuzzle(GameKind $kind = GameKind::Jigsaw): PuzzleDirectory
{
    if (!isset(VectorWorkspaces::$built[$kind->value])) {
        $dir = Fixtures::tempDir('ip-vector-built-');
        imagepng(Fixtures::coordinateImage(320, 240, false), $dir . '/photo.png');
        $jigsaw = $kind === GameKind::Jigsaw;
        (new DefinitionBuilder())->build($dir . '/photo.png', $dir . '/out', $kind, $jigsaw ? 'custom' : 'easy', $jigsaw ? ['rows' => 3, 'cols' => 4] : [], 7);
        VectorWorkspaces::$built[$kind->value] = PuzzleDirectory::open($dir . '/out');
    }

    return VectorWorkspaces::$built[$kind->value];
}

/** Copy of a built puzzle whose definition points at a remote image. */
function remotePuzzle(): PuzzleDirectory
{
    $doc = Fixtures::json(vectorPuzzle()->path . '/definition.json');
    unset($doc['id'], $doc['$schema']);
    $media = Fixtures::object($doc['media'] ?? null);
    $media['image'] = 'https://example.com/photo.png';
    $doc['media'] = $media;
    $doc = ['id' => 'gd1-' . CanonicalJson::digest($doc), '$schema' => GameDefinition::SCHEMA_ID] + $doc;
    $dir = vectorDir();
    file_put_contents($dir . '/definition.json', json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

    return PuzzleDirectory::open($dir);
}

function svgDocument(string $svg): DOMXPath
{
    $dom = new DOMDocument();
    expect($dom->loadXML($svg, LIBXML_NONET))->toBeTrue();
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');

    return $xpath;
}

/**
 * @return list<DOMNode|DOMNameSpaceNode>
 */
function svgAll(DOMXPath $xpath, string $query): array
{
    $nodes = $xpath->query($query);
    expect($nodes)->toBeInstanceOf(DOMNodeList::class, $query);

    return $nodes === false ? [] : array_values(iterator_to_array($nodes));
}

function svgCount(DOMXPath $xpath, string $query): int
{
    return count(svgAll($xpath, $query));
}

function svgOne(DOMXPath $xpath, string $query): DOMElement
{
    $node = svgAll($xpath, $query)[0] ?? null;
    if (!$node instanceof DOMElement) {
        throw new RuntimeException("No element matches {$query}.");
    }

    return $node;
}

/**
 * @return list<array{0: float, 1: float}>
 */
function svgPoints(string $d): array
{
    preg_match_all('/[ML](-?[0-9.]+) (-?[0-9.]+)/', $d, $matches, PREG_SET_ORDER);

    return array_map(static fn (array $m): array => [(float) $m[1], (float) $m[2]], $matches);
}

/**
 * Even-odd point-in-polygon test.
 *
 * @param list<array{0: float, 1: float}> $polygon
 */
function insidePolygon(array $polygon, float $x, float $y): bool
{
    $inside = false;
    $n = count($polygon);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$xi, $yi] = $polygon[$i];
        [$xj, $yj] = $polygon[$j];
        if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
            $inside = !$inside;
        }
    }

    return $inside;
}

/**
 * @param list<array{0: float, 1: float}> $polygon
 */
function distanceToPolygon(array $polygon, float $x, float $y): float
{
    $best = INF;
    $n = count($polygon);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$ax, $ay] = $polygon[$j];
        [$bx, $by] = $polygon[$i];
        $len = ($bx - $ax) ** 2 + ($by - $ay) ** 2;
        $t = $len === 0.0 ? 0.0 : max(0.0, min(1.0, (($x - $ax) * ($bx - $ax) + ($y - $ay) * ($by - $ay)) / $len));
        $best = min($best, hypot($x - ($ax + $t * ($bx - $ax)), $y - ($ay + $t * ($by - $ay))));
    }

    return $best;
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function vectorRgb(GdImage $image, int $x, int $y): array
{
    $c = Fixtures::rgba($image, $x, $y);

    return [$c['r'], $c['g'], $c['b']];
}

/** Mean absolute RGB difference of two same-size images, 0..255. */
function meanDifference(GdImage $a, GdImage $b, int $step = 1): float
{
    $sum = 0;
    $count = 0;
    for ($y = 0; $y < imagesy($a); $y += $step) {
        for ($x = 0; $x < imagesx($a); $x += $step) {
            [$ar, $ag, $ab] = vectorRgb($a, $x, $y);
            [$br, $bg, $bb] = vectorRgb($b, $x, $y);
            $sum += abs($ar - $br) + abs($ag - $bg) + abs($ab - $bb);
            $count += 3;
        }
    }

    return $sum / $count;
}

/**
 * Renders PDF pages to PNGs with pdftoppm.
 *
 * @return list<GdImage>
 */
function pdfPages(string $pdf, int $dpi): array
{
    $tool = vectorTool('pdftoppm');
    $dir = vectorDir();
    file_put_contents($dir . '/doc.pdf', $pdf);
    [$code, , $err] = vectorRun([$tool, '-r', (string) $dpi, '-png', $dir . '/doc.pdf', $dir . '/page']);
    expect($code)->toBe(0, $err);
    $files = Fixtures::glob($dir . '/page-*.png');
    sort($files);

    return array_map(static fn (string $f): GdImage => Fixtures::load($f), $files);
}

afterEach(function (): void {
    foreach (VectorWorkspaces::$dirs as $dir) {
        Fixtures::removeDir($dir);
    }
    VectorWorkspaces::$dirs = [];
});

afterAll(function (): void {
    foreach (VectorWorkspaces::$built as $puzzle) {
        Fixtures::removeDir(dirname($puzzle->path));
    }
    VectorWorkspaces::$built = [];
});

describe('PuzzleDirectory', function (): void {
    it('lists the files the definition references and resolves them inside the directory', function (): void {
        $puzzle = vectorPuzzle();
        $files = $puzzle->referencedFiles();
        expect($files)->toContain($puzzle->definition->image)
            ->and(count($files))->toBe(13);
        foreach ($files as $file) {
            expect(is_file($puzzle->file($file)))->toBeTrue();
        }
    });

    it('rejects paths that leave the directory', function (string $path): void {
        expect(fn () => vectorPuzzle()->file($path))->toThrow(InvalidArgumentException::class);
    })->with(['../photo.png', 'pieces/../../photo.png', '/etc/passwd', 'https://example.com/a.png', 'missing.png']);

    it('rejects a symlink that resolves outside the directory', function (): void {
        $dir = vectorDir();
        foreach (Fixtures::glob(vectorPuzzle()->path . '/*') as $entry) {
            if (is_file($entry)) {
                copy($entry, $dir . '/' . basename($entry));
            }
        }
        $outside = vectorDir();
        file_put_contents($outside . '/secret.png', 'x');
        symlink($outside . '/secret.png', $dir . '/link.png');
        expect(fn () => PuzzleDirectory::open($dir)->file('link.png'))->toThrow(InvalidArgumentException::class, 'outside');
    });

    it('needs a definition.json', function (): void {
        expect(fn () => PuzzleDirectory::open(vectorDir()))->toThrow(InvalidArgumentException::class, 'No definition.json');
    });
});

describe('SvgExporter', function (): void {
    it('draws one clipped piece and one outline per tile, in world coordinates', function (): void {
        $puzzle = vectorPuzzle();
        $definition = $puzzle->definition;
        $xpath = svgDocument((new SvgExporter())->render($puzzle));
        $crop = $definition->crop;

        $svg = svgOne($xpath, '/s:svg');
        expect($svg->getAttribute('viewBox'))->toBe("{$crop->x} {$crop->y} {$crop->width} {$crop->height}")
            ->and($svg->getAttribute('data-definition'))->toBe($definition->id)
            ->and(svgCount($xpath, '//s:clipPath'))->toBe(count($definition->tiles))
            ->and(svgCount($xpath, '//s:g[@id="pieces"]/s:use'))->toBe(count($definition->tiles))
            ->and(svgCount($xpath, '//s:g[@id="outlines"]/s:path'))->toBe(count($definition->tiles));

        $image = svgOne($xpath, '//s:image');
        expect($image->getAttribute('href'))->toBe($definition->image)
            ->and([$image->getAttribute('width'), $image->getAttribute('height')])->toBe([(string) $definition->sourceWidth, (string) $definition->sourceHeight]);

        foreach ($definition->tiles as $tile) {
            $outline = svgOne($xpath, "//s:path[@id='outline-{$tile->id}']");
            $expected = svgPoints(is_string($tile->attributes['path'] ?? null) ? $tile->attributes['path'] : '');
            $actual = svgPoints($outline->getAttribute('d'));
            expect(count($actual))->toBe(count($expected));
            foreach ($expected as $i => [$x, $y]) {
                expect(abs($actual[$i][0] - $x) + abs($actual[$i][1] - $y))->toBeLessThan(0.01);
            }
        }
    });

    it('uses tile rectangles for grid modes', function (): void {
        $puzzle = vectorPuzzle(GameKind::Sliding);
        $xpath = svgDocument((new SvgExporter())->render($puzzle));
        $tile = $puzzle->definition->tiles[0];
        $outline = svgOne($xpath, "//s:path[@id='outline-{$tile->id}']");
        $r = $tile->rect;
        expect(svgPoints($outline->getAttribute('d')))->toBe([
            [(float) $r->x, (float) $r->y],
            [(float) ($r->x + $r->width), (float) $r->y],
            [(float) ($r->x + $r->width), (float) ($r->y + $r->height)],
            [(float) $r->x, (float) ($r->y + $r->height)],
        ]);
    });

    it('escapes text and contains no script, event handler or foreign link', function (): void {
        $title = "<script>alert(1)</script> & \"q\" \x01 é";
        $svg = (new SvgExporter())->render(vectorPuzzle(), new SvgOptions(title: $title, description: '</desc><a href="javascript:x()">x</a>'));
        $xpath = svgDocument($svg);
        expect(svgOne($xpath, '//s:title')->textContent)->toBe('<script>alert(1)</script> & "q"  é')
            ->and(svgOne($xpath, '//s:desc')->textContent)->toBe('</desc><a href="javascript:x()">x</a>')
            ->and(svgCount($xpath, '//s:script | //s:a | //s:foreignObject | //s:style'))->toBe(0)
            ->and(svgCount($xpath, '//@*[starts-with(name(), "on")]'))->toBe(0);
        $hrefs = [];
        foreach (svgAll($xpath, '//@href | //@*[local-name()="href"]') as $attribute) {
            $hrefs[] = $attribute->nodeValue;
        }
        expect(array_values(array_unique(array_filter($hrefs, static fn (?string $h): bool => !str_starts_with((string) $h, '#')))))->toBe([vectorPuzzle()->definition->image]);
    });

    it('embeds the image as a data URI within a size limit', function (): void {
        $puzzle = vectorPuzzle();
        $xpath = svgDocument((new SvgExporter())->render($puzzle, new SvgOptions(image: SvgOptions::IMAGE_EMBED)));
        $image = svgOne($xpath, '//s:image');
        expect($image->getAttribute('href'))->toStartWith('data:image/png;base64,')
            ->and(base64_decode(substr($image->getAttribute('href'), 22), true))->toBe(file_get_contents($puzzle->file($puzzle->definition->image)));
        expect(fn () => (new SvgExporter(100))->render($puzzle, new SvgOptions(image: SvgOptions::IMAGE_EMBED)))
            ->toThrow(ResourceLimitExceededException::class, 'link it instead');
    });

    it('exports only outlines for a remote image', function (): void {
        $puzzle = remotePuzzle();
        expect(fn () => (new SvgExporter())->render($puzzle))->toThrow(InvalidArgumentException::class, 'remote');
        $xpath = svgDocument((new SvgExporter())->render($puzzle, new SvgOptions(image: SvgOptions::IMAGE_NONE, pieces: false)));
        expect(svgCount($xpath, '//s:image'))->toBe(0)
            ->and(svgCount($xpath, '//s:g[@id="outlines"]/s:path'))->toBe(12);
    });

    it('validates its options', function (callable $make, string $message): void {
        expect($make)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'image' => [fn () => new SvgOptions(image: 'remote'), 'image'],
        'nothing to draw' => [fn () => new SvgOptions(pieces: false, outlines: false), 'pieces, outlines'],
        'pieces without image' => [fn () => new SvgOptions(image: SvgOptions::IMAGE_NONE), 'image'],
        'stroke' => [fn () => new SvgOptions(stroke: 'red;x'), 'stroke'],
        'stroke width' => [fn () => new SvgOptions(strokeWidth: -1.0), 'stroke width'],
        'physical width' => [fn () => new SvgOptions(widthMm: 0.0), 'width'],
        'precision' => [fn () => new SvgOptions(precision: 9), 'precision'],
    ]);

    it('writes physical sizes and refuses to overwrite a file', function (): void {
        $target = vectorDir() . '/puzzle.svg';
        (new SvgExporter())->write(vectorPuzzle(), $target, new SvgOptions(widthMm: 160.0));
        $svg = svgOne(svgDocument((string) file_get_contents($target)), '/s:svg');
        expect([$svg->getAttribute('width'), $svg->getAttribute('height')])->toBe(['160mm', '120mm'])
            ->and(fn () => (new SvgExporter())->write(vectorPuzzle(), $target))->toThrow(ExportFailedException::class, 'must not exist');
    });

    it('reassembles the source picture when rendered', function (): void {
        $tool = vectorTool('rsvg-convert');
        $puzzle = vectorPuzzle();
        $dir = vectorDir();
        file_put_contents($dir . '/pieces.svg', (new SvgExporter())->render($puzzle, new SvgOptions(image: SvgOptions::IMAGE_EMBED, outlines: false)));
        [$code, , $err] = vectorRun([$tool, '--background-color=white', '-o', $dir . '/pieces.png', $dir . '/pieces.svg']);
        expect($code)->toBe(0, $err);
        $rendered = Fixtures::load($dir . '/pieces.png');
        $crop = $puzzle->definition->crop;
        expect([imagesx($rendered), imagesy($rendered)])->toBe([$crop->width, $crop->height]);
        $source = Fixtures::load($puzzle->file($puzzle->definition->image));
        $expected = imagecrop($source, ['x' => $crop->x, 'y' => $crop->y, 'width' => $crop->width, 'height' => $crop->height]);
        assert($expected instanceof GdImage);
        // Antialiased clip edges blend with the white background; the interior is exact.
        expect(meanDifference($rendered, $expected))->toBeLessThan(2.0);
    })->skip(fn (): bool => missingTool('rsvg-convert'), 'rsvg-convert not installed');
});

describe('outline agreement', function (): void {
    it('matches the alpha mask of every raster piece', function (): void {
        $puzzle = vectorPuzzle();
        $outlines = $puzzle->outlines();
        foreach ($puzzle->definition->tiles as $tile) {
            $piece = Fixtures::load($puzzle->file(ImagePuzzle\Game\Doc::stringValue($tile->attributes['image'] ?? null, 'tile image')));
            // The piece image covers the rendered box: the cell plus its tabs.
            $rect = Tile::rect($tile->attributes['rendered'] ?? null, "tile {$tile->id} rendered");
            expect([imagesx($piece), imagesy($piece)])->toBe([$rect->width, $rect->height]);
            $disagree = 0;
            $far = 0;
            for ($y = 0; $y < $rect->height; $y++) {
                for ($x = 0; $x < $rect->width; $x++) {
                    $wx = $rect->x + $x + 0.5;
                    $wy = $rect->y + $y + 0.5;
                    $opaque = Fixtures::alpha($piece, $x, $y) < 64;
                    if ($opaque === insidePolygon($outlines[$tile->id], $wx, $wy)) {
                        continue;
                    }
                    $disagree++;
                    // Only antialiased boundary pixels may disagree.
                    if (distanceToPolygon($outlines[$tile->id], $wx, $wy) > 1.0) {
                        $far++;
                    }
                }
            }
            expect($far)->toBe(0, "tile {$tile->id}: {$far} pixels disagree away from the outline")
                ->and($disagree)->toBeLessThan($rect->width + $rect->height);
        }
    });
});

describe('PdfExporter', function (): void {
    it('fits the board on one sheet plus a reference page', function (): void {
        $layout = (new PdfExporter())->layout(vectorPuzzle());
        // 4:3 board on A4 (277 x 190 mm printable in landscape, 190 x 277 in portrait):
        // landscape prints it larger, 253.3 x 190 mm against 190 x 142.5 mm.
        expect($layout['pages'])->toBe(2)
            ->and($layout['sheets'])->toBe([1, 1])
            ->and($layout['paper'])->toBe([297.0, 210.0])
            ->and($layout['board'][0])->toEqualWithDelta(253.333, 0.001)
            ->and($layout['board'][1])->toEqualWithDelta(190.0, 0.001);
    });

    it('tiles a board larger than a sheet', function (): void {
        $layout = (new PdfExporter())->layout(vectorPuzzle(), new PdfOptions(paper: 'a4', orientation: 'portrait', widthMm: 400.0, reference: false));
        // 190 x 277 mm printable: 400 x 300 mm needs 3 columns and 2 rows.
        expect($layout['sheets'])->toBe([3, 2])->and($layout['pages'])->toBe(6);
    });

    it('writes a valid, deterministic PDF with the paper size', function (string $paper, string $size): void {
        $pdf = (new PdfExporter())->render(vectorPuzzle(), new PdfOptions(paper: $paper, orientation: 'portrait'));
        expect((new PdfExporter())->render(vectorPuzzle(), new PdfOptions(paper: $paper, orientation: 'portrait')))->toBe($pdf);
        $info = vectorTool('pdfinfo');
        $qpdf = vectorTool('qpdf');
        $file = vectorDir() . '/doc.pdf';
        file_put_contents($file, $pdf);
        [$code, $out] = vectorRun([$info, $file]);
        expect($code)->toBe(0)
            ->and($out)->toMatch('/^Pages:\s+2$/m')
            ->and($out)->toMatch('/^Page size:\s+' . preg_quote($size, '/') . '$/m');
        [$code, $out, $err] = vectorRun([$qpdf, '--check', $file]);
        expect($code)->toBe(0, $out . $err)->and($out)->toContain('No syntax or stream encoding errors');
    })->with([
        'A4' => ['a4', '595.28 x 841.89 pts (A4)'],
        'A3' => ['a3', '841.89 x 1190.55 pts (A3)'],
    ])->skip(fn (): bool => missingTool('pdfinfo', 'qpdf'), 'pdfinfo or qpdf not installed');

    it('prints the board at the requested physical size and position', function (): void {
        $puzzle = vectorPuzzle();
        $options = new PdfOptions(orientation: 'portrait', widthMm: 160.0, cutLines: false, reference: false);
        $pages = pdfPages((new PdfExporter())->render($puzzle, $options), 100);
        expect($pages)->toHaveCount(1);
        $page = $pages[0];
        $px = 100 / 25.4;
        expect(imagesx($page))->toEqualWithDelta(210 * $px, 1.0)->and(imagesy($page))->toEqualWithDelta(297 * $px, 1.0);
        // Board: 160 x 120 mm at the 10 mm margin; the source coordinate image maps linearly.
        $crop = $puzzle->definition->crop;
        $board = imagecrop($page, ['x' => (int) round(10 * $px), 'y' => (int) round(10 * $px), 'width' => (int) round(160 * $px), 'height' => (int) round(120 * $px)]);
        assert($board instanceof GdImage);
        $source = Fixtures::load($puzzle->file($puzzle->definition->image));
        $expected = imagecrop($source, ['x' => $crop->x, 'y' => $crop->y, 'width' => $crop->width, 'height' => $crop->height]);
        assert($expected instanceof GdImage);
        $scaled = imagescale($expected, imagesx($board), imagesy($board), IMG_BICUBIC);
        assert($scaled instanceof GdImage);
        expect(meanDifference($board, $scaled, 3))->toBeLessThan(6.0);
        // Outside the board the sheet stays white (apart from the footer and crop marks).
        [$r, $g, $b] = vectorRgb($page, (int) round(190 * $px), (int) round(200 * $px));
        expect([$r, $g, $b])->toBe([255, 255, 255]);
    })->skip(fn (): bool => missingTool('pdftoppm'), 'pdftoppm not installed');

    it('draws cut lines on the piece outlines', function (): void {
        $puzzle = vectorPuzzle();
        $render = static fn (bool $lines): GdImage => pdfPages((new PdfExporter())->render($puzzle, new PdfOptions(orientation: 'portrait', widthMm: 160.0, cutLines: $lines, lineColor: '#ff00ff', lineWidthPt: 1.0, reference: false)), 150)[0];
        $with = $render(true);
        $without = $render(false);
        $px = 150 / 25.4;
        $crop = $puzzle->definition->crop;
        $scale = 160 * $px / $crop->width;
        $hits = 0;
        $samples = 0;
        foreach ($puzzle->outlines() as $polygon) {
            foreach ($polygon as $i => [$x, $y]) {
                if ($i % 7 !== 0) {
                    continue;
                }
                $samples++;
                $cx = (int) round(10 * $px + ($x - $crop->x) * $scale);
                $cy = (int) round(10 * $px + ($y - $crop->y) * $scale);
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        [$r, $g, $b] = vectorRgb($with, $cx + $dx, $cy + $dy);
                        if ($r > 200 && $g < 80 && $b > 200 && vectorRgb($without, $cx + $dx, $cy + $dy) !== vectorRgb($with, $cx + $dx, $cy + $dy)) {
                            $hits++;
                            break 2;
                        }
                    }
                }
            }
        }
        // Every sampled outline point has a magenta line pixel within 2 px (0.34 mm).
        expect($hits)->toBe($samples);
    })->skip(fn (): bool => missingTool('pdftoppm'), 'pdftoppm not installed');

    it('continues the board across tiled sheets', function (): void {
        $puzzle = vectorPuzzle();
        $options = new PdfOptions(orientation: 'portrait', widthMm: 300.0, cutLines: false, reference: false);
        expect((new PdfExporter())->layout($puzzle, $options)['sheets'])->toBe([2, 1]);
        $pages = pdfPages((new PdfExporter())->render($puzzle, $options), 100);
        expect($pages)->toHaveCount(2);
        $px = 100 / 25.4;
        // The last printable column of sheet 1 and the first of sheet 2 are neighbours on the board.
        $right = (int) floor(200 * $px) - 2;
        $left = (int) ceil(10 * $px) + 1;
        $diff = 0;
        for ($y = (int) round(20 * $px); $y < (int) round(200 * $px); $y += 5) {
            [$r1, $g1, $b1] = vectorRgb($pages[0], $right, $y);
            [$r2, $g2, $b2] = vectorRgb($pages[1], $left, $y);
            $diff = max($diff, abs($r1 - $r2) + abs($g1 - $g2) + abs($b1 - $b2));
        }
        expect($diff)->toBeLessThan(30);
        // The second sheet only holds the remaining 110 mm of the board.
        expect(vectorRgb($pages[1], (int) round(130 * $px), (int) round(100 * $px))[0])->toBe(255);
    })->skip(fn (): bool => missingTool('pdftoppm'), 'pdftoppm not installed');

    it('labels the reference page with piece numbers and sanitised text', function (): void {
        $tool = vectorTool('pdftotext');
        $file = vectorDir() . '/doc.pdf';
        file_put_contents($file, (new PdfExporter())->render(vectorPuzzle(), new PdfOptions(title: 'Café (draft) \\ end')));
        [$code, $text] = vectorRun([$tool, '-f', '2', '-l', '2', $file, '-']);
        expect($code)->toBe(0)
            ->and($text)->toContain('Caf? (draft) \\ end - 12 pieces');
        foreach (range(1, 12) as $number) {
            expect($text)->toMatch('/(^|\s)' . $number . '(\s|$)/');
        }
        [, $first] = vectorRun([$tool, '-f', '1', '-l', '1', $file, '-']);
        expect($first)->toContain('Sheet 1 of 1')->and($first)->toContain('print without scaling');
    })->skip(fn (): bool => missingTool('pdftotext'), 'pdftotext not installed');

    it('needs a local image and validates its options', function (): void {
        expect(fn () => (new PdfExporter())->render(remotePuzzle()))->toThrow(InvalidArgumentException::class, 'remote')
            ->and(fn () => new PdfOptions(paper: 'letter'))->toThrow(InvalidArgumentException::class, 'paper')
            ->and(fn () => new PdfOptions(orientation: 'square'))->toThrow(InvalidArgumentException::class, 'orientation')
            ->and(fn () => new PdfOptions(marginMm: 1.0))->toThrow(InvalidArgumentException::class, 'margin')
            ->and(fn () => new PdfOptions(widthMm: 1.0))->toThrow(InvalidArgumentException::class, 'width')
            ->and(fn () => new PdfOptions(lineColor: 'red'))->toThrow(InvalidArgumentException::class, 'colour')
            ->and(fn () => new PdfOptions(maxDpi: 10))->toThrow(InvalidArgumentException::class, 'resolution')
            ->and(fn () => new PdfOptions(jpegQuality: 0))->toThrow(InvalidArgumentException::class, 'quality');
    });

    it('downsamples the image to the resolution limit', function (): void {
        $pdf = (new PdfExporter())->render(vectorPuzzle(), new PdfOptions(widthMm: 25.4, maxDpi: 100, reference: false));
        // 1 inch at 100 dpi: 100 px wide, never the 320 px source.
        expect($pdf)->toMatch('#/Subtype /Image /Width 100 /Height 75 #');
    });
});

describe('modes without cut pieces', function (): void {
    it('refuses SVG and PDF output for a nonogram instead of printing its solution', function (): void {
        $puzzle = vectorPuzzle(GameKind::Nonogram);
        expect(fn () => (new SvgExporter())->render($puzzle))->toThrow(InvalidArgumentException::class, 'no pieces to cut')
            ->and(fn () => (new PdfExporter())->render($puzzle))->toThrow(InvalidArgumentException::class, 'no pieces to cut')
            ->and(fn () => (new PdfExporter())->layout($puzzle))->toThrow(InvalidArgumentException::class, 'no pieces to cut');
    });
});
