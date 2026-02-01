<?php

declare(strict_types=1);

namespace ImagePuzzle;

final class ExportResult
{
    /**
     * @param list<string> $files
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        private readonly string $outputDirectory,
        private readonly array $files,
        private readonly string $manifestPath,
        private readonly array $manifest,
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
}
