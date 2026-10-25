<?php

declare(strict_types=1);

namespace ImagePuzzle;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\ResourceLimitExceededException;

/**
 * Resource budgets applied before decoding, allocation, forking and output.
 *
 * Defaults were chosen from the measured memory envelope documented in
 * docs/OPERATIONS.md: a decoded GD truecolor image costs about 5 bytes per
 * pixel (~200 MiB at the default 40 megapixel cap) and every parallel worker
 * holds its own decoded copy of the source.
 */
final readonly class ResourceLimits
{
    public const DEFAULT_MAX_INPUT_BYTES = 64 * 1024 * 1024;
    public const DEFAULT_MAX_DIMENSION = 12_000;
    public const DEFAULT_MAX_PIXELS = 40_000_000;
    public const DEFAULT_MAX_PIECES = 2_500;
    public const DEFAULT_MAX_WORKERS = 8;
    public const DEFAULT_WORKER_MEMORY_BUDGET = 1024 * 1024 * 1024;

    /** Estimated native bytes per decoded truecolor pixel (GD int32 storage plus row overhead). */
    public const BYTES_PER_PIXEL_ESTIMATE = 5;

    public function __construct(
        public int $maxInputBytes = self::DEFAULT_MAX_INPUT_BYTES,
        public int $maxWidth = self::DEFAULT_MAX_DIMENSION,
        public int $maxHeight = self::DEFAULT_MAX_DIMENSION,
        public int $maxPixels = self::DEFAULT_MAX_PIXELS,
        public int $maxPieces = self::DEFAULT_MAX_PIECES,
        public int $maxWorkers = self::DEFAULT_MAX_WORKERS,
        public int $workerMemoryBudget = self::DEFAULT_WORKER_MEMORY_BUDGET,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("ResourceLimits::\${$name} must be at least 1.");
            }
        }
    }

    public function assertInputBytes(int $bytes, string $label): void
    {
        if ($bytes > $this->maxInputBytes) {
            throw new ResourceLimitExceededException(
                "{$label} is {$bytes} bytes; the configured limit is {$this->maxInputBytes} bytes."
            );
        }
    }

    public function assertDimensions(int $width, int $height, string $label): void
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException("{$label} has invalid dimensions {$width}x{$height}.");
        }
        if ($width > $this->maxWidth || $height > $this->maxHeight) {
            throw new ResourceLimitExceededException(
                "{$label} is {$width}x{$height}; the configured maximum is {$this->maxWidth}x{$this->maxHeight}."
            );
        }
        // Integer overflow is impossible here: both factors are bounded by maxWidth/maxHeight.
        $pixels = $width * $height;
        if ($pixels > $this->maxPixels) {
            throw new ResourceLimitExceededException(
                "{$label} has {$pixels} pixels; the configured limit is {$this->maxPixels} pixels."
            );
        }
    }

    public function assertPieceCount(int $rows, int $cols): void
    {
        if ($rows > $this->maxPieces || $cols > $this->maxPieces || $rows * $cols > $this->maxPieces) {
            throw new ResourceLimitExceededException(
                "A {$rows}x{$cols} grid exceeds the configured limit of {$this->maxPieces} pieces."
            );
        }
    }

    /**
     * Number of workers that fit the worker cap, the piece count and the
     * aggregate memory budget (each worker decodes the full source).
     */
    public function workerCapacity(int $requested, int $pieces, int $sourceWidth, int $sourceHeight): int
    {
        $perWorker = max(1, $sourceWidth * $sourceHeight * self::BYTES_PER_PIXEL_ESTIMATE * 2);
        $byMemory = intdiv($this->workerMemoryBudget, $perWorker);

        return max(0, min($requested, $this->maxWorkers, $pieces, $byMemory));
    }
}
