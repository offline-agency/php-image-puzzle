<?php

declare(strict_types=1);

namespace ImagePuzzle;

use ImagePuzzle\Exception\InvalidArgumentException;

final class ExportOptions
{
    public const FORMAT_PNG = 'png';
    public const FORMAT_JPEG = 'jpeg';

    private const DEFAULT_PATTERN = 'piece_{row}_{col}.png';
    private const DEFAULT_MANIFEST_FILENAME = 'manifest.json';

    /**
     * @param ?callable(Piece, string): void $onPieceBeforeSave Invoked before writing each piece file.
     * @param ?callable(Piece, string): void $onPieceAfterSave Invoked after each piece file is written.
     * @param ?callable(array): array $onManifestBuild Receives default manifest array; returns (possibly modified) array to write.
     */
    public function __construct(
        private readonly string $format = self::FORMAT_PNG,
        private readonly int $quality = 9,
        private readonly string $filenamePattern = self::DEFAULT_PATTERN,
        private readonly bool $overwrite = true,
        private readonly string $manifestFilename = self::DEFAULT_MANIFEST_FILENAME,
        private readonly mixed $onPieceBeforeSave = null,
        private readonly mixed $onPieceAfterSave = null,
        private readonly mixed $onManifestBuild = null,
    ) {
        $this->validateFilename('filenamePattern', $filenamePattern);
        $this->validateFilename('manifestFilename', $manifestFilename);
    }

    private function validateFilename(string $paramName, string $value): void
    {
        if ($value === '' || $value === '.' || $value === '..') {
            throw new InvalidArgumentException("{$paramName} must be a valid filename, not empty and not '.' or '..'.");
        }
        if (str_contains($value, '/') || str_contains($value, '\\')) {
            throw new InvalidArgumentException("{$paramName} must not contain path separators.");
        }
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getQuality(): int
    {
        return $this->quality;
    }

    public function getFilenamePattern(): string
    {
        return $this->filenamePattern;
    }

    public function getOverwrite(): bool
    {
        return $this->overwrite;
    }

    public function getManifestFilename(): string
    {
        return $this->manifestFilename;
    }

    /** @return mixed Callable (Piece, string): void or null */
    public function getOnPieceBeforeSave(): mixed
    {
        return $this->onPieceBeforeSave;
    }

    /** @return mixed Callable (Piece, string): void or null */
    public function getOnPieceAfterSave(): mixed
    {
        return $this->onPieceAfterSave;
    }

    /** @return mixed Callable (array): array or null */
    public function getOnManifestBuild(): mixed
    {
        return $this->onManifestBuild;
    }

    public function withFormat(string $format): self
    {
        return new self(
            $format,
            $this->quality,
            $this->filenamePattern,
            $this->overwrite,
            $this->manifestFilename,
            $this->onPieceBeforeSave,
            $this->onPieceAfterSave,
            $this->onManifestBuild,
        );
    }

    public function withQuality(int $quality): self
    {
        return new self(
            $this->format,
            $quality,
            $this->filenamePattern,
            $this->overwrite,
            $this->manifestFilename,
            $this->onPieceBeforeSave,
            $this->onPieceAfterSave,
            $this->onManifestBuild,
        );
    }

    public function withFilenamePattern(string $filenamePattern): self
    {
        $this->validateFilename('filenamePattern', $filenamePattern);
        return new self(
            $this->format,
            $this->quality,
            $filenamePattern,
            $this->overwrite,
            $this->manifestFilename,
            $this->onPieceBeforeSave,
            $this->onPieceAfterSave,
            $this->onManifestBuild,
        );
    }

    public function withOverwrite(bool $overwrite): self
    {
        return new self(
            $this->format,
            $this->quality,
            $this->filenamePattern,
            $overwrite,
            $this->manifestFilename,
            $this->onPieceBeforeSave,
            $this->onPieceAfterSave,
            $this->onManifestBuild,
        );
    }

    public function withManifestFilename(string $manifestFilename): self
    {
        $this->validateFilename('manifestFilename', $manifestFilename);
        return new self(
            $this->format,
            $this->quality,
            $this->filenamePattern,
            $this->overwrite,
            $manifestFilename,
            $this->onPieceBeforeSave,
            $this->onPieceAfterSave,
            $this->onManifestBuild,
        );
    }

    /** @param ?callable(Piece, string): void $callback */
    public function withOnPieceBeforeSave(?callable $callback): self
    {
        return new self(
            $this->format,
            $this->quality,
            $this->filenamePattern,
            $this->overwrite,
            $this->manifestFilename,
            $callback,
            $this->onPieceAfterSave,
            $this->onManifestBuild,
        );
    }

    /** @param ?callable(Piece, string): void $callback */
    public function withOnPieceAfterSave(?callable $callback): self
    {
        return new self(
            $this->format,
            $this->quality,
            $this->filenamePattern,
            $this->overwrite,
            $this->manifestFilename,
            $this->onPieceBeforeSave,
            $callback,
            $this->onManifestBuild,
        );
    }

    /** @param ?callable(array): array $callback */
    public function withOnManifestBuild(?callable $callback): self
    {
        return new self(
            $this->format,
            $this->quality,
            $this->filenamePattern,
            $this->overwrite,
            $this->manifestFilename,
            $this->onPieceBeforeSave,
            $this->onPieceAfterSave,
            $callback,
        );
    }

    /**
     * Resolve piece filename from pattern. Placeholders: {row}, {col}.
     * When $rows and $cols are provided: {index} (row * cols + col), {rows}, {cols} are also supported.
     */
    public function resolvePieceFilename(int $row, int $col, ?int $rows = null, ?int $cols = null): string
    {
        $replacements = [(string) $row, (string) $col];
        $placeholders = ['{row}', '{col}'];
        if ($rows !== null && $cols !== null) {
            $placeholders[] = '{index}';
            $placeholders[] = '{rows}';
            $placeholders[] = '{cols}';
            $replacements[] = (string) ($row * $cols + $col);
            $replacements[] = (string) $rows;
            $replacements[] = (string) $cols;
        }
        $name = str_replace($placeholders, $replacements, $this->filenamePattern);
        if ($this->format === self::FORMAT_JPEG && str_ends_with($name, '.png')) {
            $name = substr($name, 0, -4) . '.jpg';
        }
        return $name;
    }
}
