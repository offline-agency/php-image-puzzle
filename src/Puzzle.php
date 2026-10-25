<?php

declare(strict_types=1);

namespace ImagePuzzle;

use ImagePuzzle\ExporterInterface;

/**
 * Generated puzzle: grid metadata plus lazily rendered pieces.
 */
final class Puzzle
{
    /**
     * @param list<list<Piece>> $pieces 2D grid indexed by [row][col]
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
        if ($row < 0 || $row >= $this->rows || $col < 0 || $col >= $this->cols || !isset($this->pieces[$row][$col])) {
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
