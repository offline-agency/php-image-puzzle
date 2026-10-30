<?php

declare(strict_types=1);

namespace ImagePuzzle;

/**
 * Writes a puzzle's pieces and manifest. Implementations must perform the
 * same preflight, overwrite and publication semantics as {@see Exporter}.
 */
interface ExporterInterface
{
    public function export(Puzzle $puzzle, string $directory, ExportOptions $options, ?string $baseDirectory = null): ExportResult;
}
