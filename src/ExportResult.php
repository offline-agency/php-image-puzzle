<?php

declare(strict_types=1);

namespace ImagePuzzle;

final class ExportResult
{
    /**
     * @param list<string> $files every piece file plus the manifest (last)
     * @param array<string, mixed> $manifest
     * @param list<string> $reusedFiles existing piece files kept under overwrite=false
     */
    public function __construct(
        private readonly string $outputDirectory,
        private readonly array $files,
        private readonly string $manifestPath,
        private readonly array $manifest,
        private readonly array $reusedFiles = [],
        private readonly ?string $generationId = null,
    ) {
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
