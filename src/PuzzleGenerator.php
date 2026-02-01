<?php

declare(strict_types=1);

namespace ImagePuzzle;

use GdImage;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\SplitStrategy\DimensionSplitStrategy;

final class PuzzleGenerator
{
    public function __construct(
        private readonly ?EdgeStyleInterface $edgeStyle = null,
        private readonly ?ImageLoader $imageLoader = null,
        private readonly ?Exporter $exporter = null,
        private readonly ?DimensionSplitStrategy $splitStrategy = null,
    ) {
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
    ): ExportResult {
        $generator = new self($edgeStyle);
        $puzzle = $generator->generateFromFile($inputPath, $rows, $cols);
        return $puzzle->export($outputDir, $options ?? new ExportOptions());
    }

    public function generateFromFile(string $path, int $rows, int $cols): Puzzle
    {
        $this->validateGrid($rows, $cols);
        $loader = $this->imageLoader ?? new ImageLoader();
        $image = $loader->loadFromFile($path);
        $sourceBasename = basename($path);
        return $this->buildPuzzle($image, $rows, $cols, $sourceBasename);
    }

    public function generateFromGd(GdImage $image, int $rows, int $cols): Puzzle
    {
        $this->validateGrid($rows, $cols);
        return $this->buildPuzzle($image, $rows, $cols, null);
    }

    /**
     * Generate only the pieces for the given indices (for parallel export).
     *
     * @param array<int, array{0: int, 1: int}> $pieceIndices List of [row, col] pairs
     * @return list<Piece>
     */
    public function generatePiecesFromGd(GdImage $image, int $rows, int $cols, array $pieceIndices): array
    {
        $this->validateGrid($rows, $cols);
        $width = imagesx($image);
        $height = imagesy($image);
        $edgeStyle = $this->edgeStyle ?? new NoEdgeStyle();
        $pieceWidths = $this->computeDimensionSplits($width, $cols);
        $pieceHeights = $this->computeDimensionSplits($height, $rows);

        $pieces = [];
        $yByRow = [];
        $xByCol = [];
        $y = 0;
        for ($row = 0; $row < $rows; $row++) {
            $yByRow[$row] = $y;
            $y += $pieceHeights[$row];
        }
        $x = 0;
        for ($col = 0; $col < $cols; $col++) {
            $xByCol[$col] = $x;
            $x += $pieceWidths[$col];
        }

        foreach ($pieceIndices as [$row, $col]) {
            $pieceWidth = $pieceWidths[$col];
            $pieceHeight = $pieceHeights[$row];
            $pieces[] = new Piece(
                $row,
                $col,
                $xByCol[$col],
                $yByRow[$row],
                $pieceWidth,
                $pieceHeight,
                $image,
                $width,
                $height,
                $rows,
                $cols,
                $edgeStyle,
            );
        }
        return $pieces;
    }

    /**
     * Generate only the pieces for the given indices (loads image from file).
     *
     * @param array<int, array{0: int, 1: int}> $pieceIndices List of [row, col] pairs
     * @return list<Piece>
     */
    public function generatePiecesFromFile(string $path, int $rows, int $cols, array $pieceIndices): array
    {
        $loader = $this->imageLoader ?? new ImageLoader();
        $image = $loader->loadFromFile($path);
        return $this->generatePiecesFromGd($image, $rows, $cols, $pieceIndices);
    }

    private function validateGrid(int $rows, int $cols): void
    {
        if ($rows < 1) {
            throw new InvalidArgumentException('Rows must be at least 1.');
        }
        if ($cols < 1) {
            throw new InvalidArgumentException('Cols must be at least 1.');
        }
    }

    private function buildPuzzle(GdImage $image, int $rows, int $cols, ?string $sourceBasename): Puzzle
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $edgeStyle = $this->edgeStyle ?? new NoEdgeStyle();
        $pieceWidths = $this->computeDimensionSplits($width, $cols);
        $pieceHeights = $this->computeDimensionSplits($height, $rows);

        $pieces = [];
        $y = 0;
        for ($row = 0; $row < $rows; $row++) {
            $pieceHeight = $pieceHeights[$row];
            $pieces[$row] = [];
            $x = 0;
            for ($col = 0; $col < $cols; $col++) {
                $pieceWidth = $pieceWidths[$col];
                $pieces[$row][$col] = new Piece(
                    $row,
                    $col,
                    $x,
                    $y,
                    $pieceWidth,
                    $pieceHeight,
                    $image,
                    $width,
                    $height,
                    $rows,
                    $cols,
                    $edgeStyle,
                );
                $x += $pieceWidth;
            }
            $y += $pieceHeight;
        }

        return new Puzzle($rows, $cols, $width, $height, $pieces, $sourceBasename, $this->exporter);
    }

    /**
     * Split a dimension into segments. Uses split strategy when set; otherwise remainder to last segment.
     * @return list<int>
     */
    private function computeDimensionSplits(int $total, int $count): array
    {
        if ($this->splitStrategy !== null) {
            $splits = $this->splitStrategy->split($total, $count);
            if (count($splits) !== $count) {
                throw new InvalidArgumentException(
                    "Split strategy returned " . count($splits) . " segments, expected {$count}."
                );
            }
            $sum = array_sum($splits);
            if ($sum !== $total) {
                throw new InvalidArgumentException(
                    "Split strategy segments sum to {$sum}, expected {$total}."
                );
            }
            return $splits;
        }
        $base = (int) floor($total / $count);
        $remainder = $total % $count;
        $splits = [];
        for ($i = 0; $i < $count; $i++) {
            $splits[] = $base + ($i === $count - 1 ? $remainder : 0);
        }
        return $splits;
    }
}
