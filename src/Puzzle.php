<?php

declare(strict_types=1);

namespace ImagePuzzle;

/**
 * Generated puzzle: grid metadata plus lazily rendered pieces.
 */
final class Puzzle
{
    /**
     * @param array<int, array<int, Piece>> $pieces indexed by [row][col]; rows of non-rectangular boards differ in length
     * @param array{topology: string, style: string, seed: int, samples: int, version: int}|null $geometry
     */
    public function __construct(
        private readonly int $rows,
        private readonly int $cols,
        private readonly int $width,
        private readonly int $height,
        private readonly array $pieces,
        private readonly ?string $sourceBasename = null,
        private readonly ?ExporterInterface $exporter = null,
        private readonly ?array $geometry = null,
    ) {
    }

    public function getRows(): int
    {
        return $this->rows;
    }

    public function getCols(): int
    {
        return $this->cols;
    }

    public function getWidth(): int
    {
        return $this->width;
    }

    public function getHeight(): int
    {
        return $this->height;
    }

    public function getPiece(int $row, int $col): Piece
    {
        // Rows of hexagonal and triangular boards hold different numbers of cells.
        if (!isset($this->pieces[$row][$col])) {
            throw new Exception\InvalidArgumentException(
                "Invalid piece index: row={$row}, col={$col} (grid is {$this->rows}x{$this->cols})"
            );
        }

        return $this->pieces[$row][$col];
    }

    /**
     * @return iterable<Piece>
     */
    public function getPieces(): iterable
    {
        foreach ($this->pieces as $rowPieces) {
            foreach ($rowPieces as $piece) {
                yield $piece;
            }
        }
    }

    public function countPieces(): int
    {
        return array_sum(array_map('count', $this->pieces));
    }

    /**
     * @return array{topology: string, style: string, seed: int, samples: int, version: int}
     */
    public function getGeometry(): array
    {
        return $this->geometry ?? ['topology' => 'rect', 'style' => 'no_edge', 'seed' => GeometryOptions::DEFAULT_SEED, 'samples' => 1, 'version' => GeometryOptions::VERSION];
    }

    public function export(string $directory, ExportOptions $options = new ExportOptions(), ?string $baseDirectory = null): ExportResult
    {
        return ($this->exporter ?? new Exporter())->export($this, $directory, $options, $baseDirectory);
    }

    public function getSourceBasename(): ?string
    {
        return $this->sourceBasename;
    }
}
