<?php

declare(strict_types=1);

namespace ImagePuzzle;

use GdImage;
use ImagePuzzle\EdgeStyle\ArcStyle;
use ImagePuzzle\EdgeStyle\CompositeEdgeStyle;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\HalfCircleStyle;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\LineStyle;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\EdgeStyle\ResolverEdgeStyle;
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;
use ImagePuzzle\EdgeStyle\SinusStyle;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Geometry\BoundaryProfileProvider;
use ImagePuzzle\Geometry\Grid;
use ImagePuzzle\Geometry\PieceRenderer;
use ImagePuzzle\Geometry\Rasterizer;
use ImagePuzzle\Geometry\ShapeBuilder;
use ImagePuzzle\Geometry\Topology\Layout;
use ImagePuzzle\Geometry\Topology\LayoutCell;
use ImagePuzzle\Geometry\Topology\RectangularTopology;
use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;

/**
 * Builds puzzles from images.
 *
 * Ownership: generateFromGd() keeps a reference to the supplied image and
 * reads it whenever a piece is rendered; it never modifies or destroys it. The
 * caller must not mutate that image while pieces are still being rendered.
 * Images loaded by generateFromFile() are owned by the returned Puzzle.
 */
final class PuzzleGenerator
{
    private readonly ResourceLimits $limits;
    private readonly GeometryOptions $geometry;

    public function __construct(
        private readonly ?EdgeStyleInterface $edgeStyle = null,
        private readonly ?ImageLoaderInterface $imageLoader = null,
        private readonly ?ExporterInterface $exporter = null,
        private readonly ?DimensionSplitStrategy $splitStrategy = null,
        ?ResourceLimits $limits = null,
        ?GeometryOptions $geometry = null,
    ) {
        $this->limits = $limits ?? new ResourceLimits();
        $this->geometry = $geometry ?? new GeometryOptions();
    }

    /**
     * One-shot: load image, generate puzzle, export to directory.
     */
    public static function generateAndExport(
        string $inputPath,
        string $outputDir,
        int $rows,
        int $cols,
        ?EdgeStyleInterface $edgeStyle = null,
        ?ExportOptions $options = null,
        ?ResourceLimits $limits = null,
        ?GeometryOptions $geometry = null,
        ?string $baseDirectory = null,
    ): ExportResult {
        $generator = new self($edgeStyle, new ImageLoader($limits), null, null, $limits, $geometry);
        $puzzle = $generator->generateFromFile($inputPath, $rows, $cols);

        return $puzzle->export($outputDir, $options ?? new ExportOptions(), $baseDirectory);
    }

    public function getLimits(): ResourceLimits
    {
        return $this->limits;
    }

    public function getGeometryOptions(): GeometryOptions
    {
        return $this->geometry;
    }

    public function getEdgeStyle(): EdgeStyleInterface
    {
        return $this->edgeStyle ?? new NoEdgeStyle();
    }

    public function generateFromFile(string $path, int $rows, int $cols): Puzzle
    {
        $this->preflight($rows, $cols);
        $loader = $this->imageLoader ?? new ImageLoader($this->limits);
        $info = $loader->inspect($path);
        Grid::assertGrid($info->orientationSwapsAxes() ? $info->height : $info->width, $info->orientationSwapsAxes() ? $info->width : $info->height, $rows, $cols);
        $image = $loader->loadFromFile($path);

        return $this->buildPuzzle($image, $rows, $cols, basename($path));
    }

    public function generateFromGd(GdImage $image, int $rows, int $cols): Puzzle
    {
        $this->preflight($rows, $cols);

        return $this->buildPuzzle($image, $rows, $cols, null);
    }

    /**
     * Build the layout (cells and shared boundaries) without creating pieces.
     */
    public function layout(int $width, int $height, int $rows, int $cols): Layout
    {
        $this->preflight($rows, $cols);
        $this->limits->assertDimensions($width, $height, 'Source image');
        if ($this->geometry->topology instanceof RectangularTopology) {
            Grid::assertGrid($width, $height, $rows, $cols);
        }
        $layout = $this->geometry->topology->layout($width, $height, $rows, $cols, $this->splitStrategy);
        $this->limits->assertCellCount(count($layout->cells));

        return $layout;
    }

    /**
     * Generate only the pieces for the given [row, col] indices (used by
     * parallel export). Indices are validated before any lookup; duplicates
     * are rejected. Outlines are identical to a full generation.
     *
     * @param array<mixed> $pieceIndices
     * @return list<Piece>
     */
    public function generatePiecesFromGd(GdImage $image, int $rows, int $cols, array $pieceIndices): array
    {
        $this->preflight($rows, $cols);
        $layout = $this->layout(imagesx($image), imagesy($image), $rows, $cols);
        // Non-rectangular rows hold a varying number of cells: bounds are
        // checked against the layout itself.
        $indices = self::validateIndices($pieceIndices, $rows, $layout->topology === 'rect' ? $cols : PHP_INT_MAX);
        $cells = [];
        foreach ($indices as [$row, $col]) {
            $cells[] = $layout->cells[LayoutCell::idFor($row, $col)]
                ?? throw new InvalidArgumentException("Invalid piece index: row={$row}, col={$col} is not a cell of this {$layout->topology} board.");
        }
        $builder = $this->shapeBuilder($layout);
        $renderer = $this->renderer();
        $pieces = [];
        foreach ($cells as $cell) {
            $pieces[] = $this->piece($cell, $builder, $image, $layout, $rows, $cols, $renderer);
        }

        return $pieces;
    }

    /**
     * @param array<mixed> $pieceIndices
     * @return list<Piece>
     */
    public function generatePiecesFromFile(string $path, int $rows, int $cols, array $pieceIndices): array
    {
        $this->preflight($rows, $cols);
        $loader = $this->imageLoader ?? new ImageLoader($this->limits);

        return $this->generatePiecesFromGd($loader->loadFromFile($path), $rows, $cols, $pieceIndices);
    }

    /**
     * Stable style identifier for manifests.
     */
    public static function styleName(EdgeStyleInterface $style): string
    {
        return match (true) {
            $style instanceof NoEdgeStyle => 'no_edge',
            $style instanceof LineStyle => 'line',
            $style instanceof JigsawStyle => 'jigsaw',
            $style instanceof ArcStyle => 'arc',
            $style instanceof HalfCircleStyle => 'half_circle',
            $style instanceof SinusStyle => 'sinus',
            $style instanceof RoundedCornersStyle => 'rounded_corners',
            $style instanceof CompositeEdgeStyle => 'composite',
            $style instanceof ResolverEdgeStyle => 'resolver',
            default => 'custom',
        };
    }

    /**
     * @param array<mixed> $indices
     * @return list<array{0: int, 1: int}>
     */
    public static function validateIndices(array $indices, int $rows, int $cols): array
    {
        $seen = [];
        $valid = [];
        foreach ($indices as $i => $pair) {
            if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2 || !is_int($pair[0]) || !is_int($pair[1])) {
                throw new InvalidArgumentException("Piece index #{$i} must be a [row, col] pair of integers.");
            }
            [$row, $col] = $pair;
            if ($row < 0 || $row >= $rows || $col < 0 || $col >= $cols) {
                throw new InvalidArgumentException("Invalid piece index: row={$row}, col={$col} (grid is {$rows}x{$cols})");
            }
            $key = $row . ':' . $col;
            if (isset($seen[$key])) {
                throw new InvalidArgumentException("Piece index row={$row}, col={$col} is requested more than once.");
            }
            $seen[$key] = true;
            $valid[] = [$row, $col];
        }

        return $valid;
    }

    private function preflight(int $rows, int $cols): void
    {
        if ($rows < 1) {
            throw new InvalidArgumentException('Rows must be at least 1.');
        }
        if ($cols < 1) {
            throw new InvalidArgumentException('Cols must be at least 1.');
        }
        $this->limits->assertPieceCount($rows, $cols);
    }

    private function buildPuzzle(GdImage $image, int $rows, int $cols, ?string $sourceBasename): Puzzle
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $layout = $this->layout($width, $height, $rows, $cols);
        $builder = $this->shapeBuilder($layout);
        $renderer = $this->renderer();

        $pieces = array_fill(0, $rows, []);
        foreach ($layout->cells as $cell) {
            $pieces[$cell->row][$cell->col] = $this->piece($cell, $builder, $image, $layout, $rows, $cols, $renderer);
        }

        return new Puzzle($rows, $cols, $width, $height, $pieces, $sourceBasename, $this->exporter, [
            'topology' => $layout->topology,
            'style' => self::styleName($this->getEdgeStyle()),
            'seed' => $this->geometry->seed,
            'samples' => $this->geometry->samples,
            'version' => GeometryOptions::VERSION,
        ]);
    }

    private function shapeBuilder(Layout $layout): ShapeBuilder
    {
        $style = $this->getEdgeStyle();
        $provider = $style instanceof BoundaryProfileProvider ? $style : new NoEdgeStyle();

        return new ShapeBuilder($layout, $provider, $this->geometry->seed);
    }

    private function renderer(): PieceRenderer
    {
        return new PieceRenderer(new Rasterizer($this->geometry->samples));
    }

    private function piece(LayoutCell $cell, ShapeBuilder $builder, GdImage $image, Layout $layout, int $rows, int $cols, PieceRenderer $renderer): Piece
    {
        return new Piece($builder->shape($cell), $image, $layout->width, $layout->height, $rows, $cols, $this->getEdgeStyle(), $renderer);
    }
}
