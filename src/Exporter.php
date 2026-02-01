<?php

declare(strict_types=1);

namespace ImagePuzzle;

use GdImage;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\Exception\ExportFailedException;

final class Exporter
{
    public function export(Puzzle $puzzle, string $directory, ExportOptions $options, ?string $baseDirectory = null): ExportResult
    {
        $directory = rtrim($directory, '/\\');
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0755, true)) {
                throw new ExportFailedException("Failed to create directory: {$directory}");
            }
        }
        if ($baseDirectory !== null) {
            $this->assertDirectoryUnderBase($directory, $baseDirectory);
        }
        if (!is_writable($directory)) {
            throw new ExportFailedException("Directory is not writable: {$directory}");
        }

        $files = [];
        $pieceEntries = [];

        $puzzleRows = $puzzle->getRows();
        $puzzleCols = $puzzle->getCols();
        foreach ($puzzle->getPieces() as $piece) {
            $filename = $options->resolvePieceFilename($piece->getRow(), $piece->getCol(), $puzzleRows, $puzzleCols);
            $safeFilename = basename($filename);
            $filepath = $directory . DIRECTORY_SEPARATOR . $safeFilename;

            if (!$options->getOverwrite() && file_exists($filepath)) {
                $files[] = $filepath;
                $pieceEntries[] = $this->pieceToManifestEntry($piece, $safeFilename);
                continue;
            }

            $onBefore = $options->getOnPieceBeforeSave();
            if ($onBefore !== null) {
                $onBefore($piece, $filepath);
            }

            $image = $piece->toGdImage();
            try {
                $saved = $this->saveImage($image, $filepath, $options);
                if (!$saved) {
                    throw new ExportFailedException("Failed to write image: {$filepath}");
                }
            } finally {
                imagedestroy($image);
            }

            $onAfter = $options->getOnPieceAfterSave();
            if ($onAfter !== null) {
                $onAfter($piece, $filepath);
            }

            $files[] = $filepath;
            $pieceEntries[] = $this->pieceToManifestEntry($piece, $safeFilename);
        }

        $manifest = [
            'source' => $puzzle->getSourceBasename(),
            'rows' => $puzzle->getRows(),
            'cols' => $puzzle->getCols(),
            'sourceWidth' => $puzzle->getWidth(),
            'sourceHeight' => $puzzle->getHeight(),
            'pieces' => $pieceEntries,
        ];

        $onManifestBuild = $options->getOnManifestBuild();
        if ($onManifestBuild !== null) {
            $manifest = $onManifestBuild($manifest);
        }

        $manifestFilename = basename($options->getManifestFilename());
        $manifestPath = $directory . DIRECTORY_SEPARATOR . $manifestFilename;
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($manifestPath, $json) === false) {
            throw new ExportFailedException("Failed to write manifest: {$manifestPath}");
        }
        $files[] = $manifestPath;

        return new ExportResult($directory, $files, $manifestPath, $manifest);
    }

    private function assertDirectoryUnderBase(string $directory, string $baseDirectory): void
    {
        $base = realpath($baseDirectory);
        if ($base === false || !is_dir($base)) {
            throw new ExportFailedException("Base directory does not exist or is not a directory: {$baseDirectory}");
        }
        $resolved = realpath($directory);
        if ($resolved === false) {
            throw new ExportFailedException("Output directory could not be resolved: {$directory}");
        }
        $basePrefix = $base . DIRECTORY_SEPARATOR;
        if ($resolved !== $base && !str_starts_with($resolved, $basePrefix)) {
            throw new ExportFailedException("Output directory must be inside base directory: {$directory}");
        }
    }

    /**
     * @return array{row: int, col: int, x: int, y: int, width: int, height: int, filename: string}
     */
    private function pieceToManifestEntry(Piece $piece, string $filename): array
    {
        return [
            'row' => $piece->getRow(),
            'col' => $piece->getCol(),
            'x' => $piece->getX(),
            'y' => $piece->getY(),
            'width' => $piece->getWidth(),
            'height' => $piece->getHeight(),
            'filename' => $filename,
        ];
    }

    /**
     * Export puzzle pieces in parallel when ext-pcntl is loaded and PHP is CLI.
     * Falls back to sequential export (full puzzle build + export) otherwise.
     */
    public function exportParallel(
        string $sourcePath,
        string $directory,
        int $rows,
        int $cols,
        ExportOptions $options,
        int $workers = 4,
        ?EdgeStyleInterface $edgeStyle = null,
        ?string $baseDirectory = null,
    ): ExportResult {
        $directory = rtrim($directory, '/\\');
        if ($workers <= 1 || !extension_loaded('pcntl') || PHP_SAPI !== 'cli') {
            $generator = new PuzzleGenerator($edgeStyle);
            $puzzle = $generator->generateFromFile($sourcePath, $rows, $cols);
            return $this->export($puzzle, $directory, $options, $baseDirectory);
        }

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new ExportFailedException("Could not get image size: {$sourcePath}");
        }
        $width = $info[0];
        $height = $info[1];
        $sourceBasename = basename($sourcePath);

        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0755, true)) {
                throw new ExportFailedException("Failed to create directory: {$directory}");
            }
        }
        if ($baseDirectory !== null) {
            $this->assertDirectoryUnderBase($directory, $baseDirectory);
        }
        if (!is_writable($directory)) {
            throw new ExportFailedException("Directory is not writable: {$directory}");
        }

        $indices = [];
        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $indices[] = [$row, $col];
            }
        }
        $chunks = array_chunk($indices, (int) ceil(count($indices) / $workers));
        $edgeStyle = $edgeStyle ?? new NoEdgeStyle();
        $tempFiles = [];
        for ($i = 0; $i < count($chunks); $i++) {
            $tempFiles[$i] = tempnam(sys_get_temp_dir(), 'image-puzzle-');
        }

        $pids = [];
        for ($i = 0; $i < count($chunks); $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                foreach ($tempFiles as $f) {
                    @unlink($f);
                }
                throw new ExportFailedException('Failed to fork worker');
            }
            if ($pid === 0) {
                $this->runExportWorker(
                    $sourcePath,
                    $directory,
                    $rows,
                    $cols,
                    $chunks[$i],
                    $options,
                    $edgeStyle,
                    $tempFiles[$i],
                );
                exit(0);
            }
            $pids[$i] = $pid;
        }

        $workerFailed = false;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) !== 0) {
                $workerFailed = true;
            }
        }
        if ($workerFailed) {
            foreach ($tempFiles as $f) {
                @unlink($f);
            }
            throw new ExportFailedException('A worker failed during parallel export.');
        }

        $pieceEntries = [];
        $files = [];
        foreach ($tempFiles as $tempFile) {
            $json = file_get_contents($tempFile);
            @unlink($tempFile);
            if ($json !== false) {
                $entries = json_decode($json, true);
                if (is_array($entries)) {
                    foreach ($entries as $entry) {
                        $pieceEntries[] = $entry;
                        if (isset($entry['filename'])) {
                            $files[] = $directory . DIRECTORY_SEPARATOR . basename($entry['filename']);
                        }
                    }
                }
            }
        }
        usort($pieceEntries, static fn (array $a, array $b): int => $a['row'] <=> $b['row'] ?: $a['col'] <=> $b['col']);

        $manifest = [
            'source' => $sourceBasename,
            'rows' => $rows,
            'cols' => $cols,
            'sourceWidth' => $width,
            'sourceHeight' => $height,
            'pieces' => $pieceEntries,
        ];
        $onManifestBuild = $options->getOnManifestBuild();
        if ($onManifestBuild !== null) {
            $manifest = $onManifestBuild($manifest);
        }
        $manifestFilename = basename($options->getManifestFilename());
        $manifestPath = $directory . DIRECTORY_SEPARATOR . $manifestFilename;
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($manifestPath, $json) === false) {
            throw new ExportFailedException("Failed to write manifest: {$manifestPath}");
        }
        $files[] = $manifestPath;

        return new ExportResult($directory, $files, $manifestPath, $manifest);
    }

    /**
     * @param list<array{0: int, 1: int}> $chunk
     */
    private function runExportWorker(
        string $sourcePath,
        string $directory,
        int $rows,
        int $cols,
        array $chunk,
        ExportOptions $options,
        EdgeStyleInterface $edgeStyle,
        string $tempFile,
    ): void {
        $loader = new ImageLoader();
        $image = $loader->loadFromFile($sourcePath);
        try {
            $generator = new PuzzleGenerator($edgeStyle, $loader);
            $pieces = $generator->generatePiecesFromGd($image, $rows, $cols, $chunk);
            $entries = [];
            foreach ($pieces as $piece) {
                $filename = $options->resolvePieceFilename($piece->getRow(), $piece->getCol(), $rows, $cols);
                $safeFilename = basename($filename);
                $filepath = $directory . DIRECTORY_SEPARATOR . $safeFilename;

                $onBefore = $options->getOnPieceBeforeSave();
                if ($onBefore !== null) {
                    $onBefore($piece, $filepath);
                }

                $pieceImage = $piece->toGdImage();
                try {
                    $saved = $this->saveImage($pieceImage, $filepath, $options);
                    if (!$saved) {
                        throw new ExportFailedException("Failed to write image: {$filepath}");
                    }
                } finally {
                    imagedestroy($pieceImage);
                }

                $onAfter = $options->getOnPieceAfterSave();
                if ($onAfter !== null) {
                    $onAfter($piece, $filepath);
                }

                $entries[] = [
                    'row' => $piece->getRow(),
                    'col' => $piece->getCol(),
                    'x' => $piece->getX(),
                    'y' => $piece->getY(),
                    'width' => $piece->getWidth(),
                    'height' => $piece->getHeight(),
                    'filename' => $safeFilename,
                ];
            }
            $json = json_encode($entries);
            if ($json === false || file_put_contents($tempFile, $json) === false) {
                throw new ExportFailedException("Failed to write worker temp file: {$tempFile}");
            }
        } finally {
            imagedestroy($image);
        }
    }

    private function saveImage(GdImage $image, string $path, ExportOptions $options): bool
    {
        return match ($options->getFormat()) {
            ExportOptions::FORMAT_PNG => imagepng($image, $path, min(9, max(0, $options->getQuality()))),
            ExportOptions::FORMAT_JPEG => imagejpeg($image, $path, min(100, max(0, $options->getQuality()))),
            default => false,
        };
    }
}
