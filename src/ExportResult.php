<?php

declare(strict_types=1);

namespace ImagePuzzle;

final class ExportResult
{
    /**
     * @param list<string> $files every piece file plus the manifest (last)
     * @param array<string, mixed> $manifest
     * @param list<string> $reusedFiles existing piece files kept under overwrite=false
     * @param list<array{peakMemory: int, maxRss: int}> $workerMemory
     */
    public function __construct(
        private readonly string $outputDirectory,
        private readonly array $files,
        private readonly string $manifestPath,
        private readonly array $manifest,
        private readonly array $reusedFiles = [],
        private readonly ?string $generationId = null,
        private readonly array $workerMemory = [],
    ) {
    }

    /**
     * Copy with per-worker memory usage attached (parallel exports).
     *
     * @param list<array{peakMemory: int, maxRss: int}> $workerMemory
     */
    public function withWorkerMemory(array $workerMemory): self
    {
        return new self($this->outputDirectory, $this->files, $this->manifestPath, $this->manifest, $this->reusedFiles, $this->generationId, $workerMemory);
    }

    /**
     * Memory reported by each parallel worker just before it exited: peak PHP
     * memory (memory_get_peak_usage(true)) and peak RSS, in bytes. Empty for
     * sequential exports and for exports that had nothing to render.
     *
     * @return list<array{peakMemory: int, maxRss: int}>
     */
    public function getWorkerMemory(): array
    {
        return $this->workerMemory;
    }

    public function getOutputDirectory(): string
    {
        return $this->outputDirectory;
    }

    /**
     * @return list<string>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    public function getManifestPath(): string
    {
        return $this->manifestPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function getManifest(): array
    {
        return $this->manifest;
    }

    /**
     * @return list<string>
     */
    public function getReusedFiles(): array
    {
        return $this->reusedFiles;
    }

    public function getGenerationId(): ?string
    {
        return $this->generationId;
    }
}
