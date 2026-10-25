<?php

declare(strict_types=1);

namespace ImagePuzzle;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Immutable export settings.
 *
 * Quality semantics are format specific and resolved lazily: PNG uses a zlib
 * compression level 0..9 (default 9), JPEG and WebP a quality 0..100
 * (defaults 85 and 80). Leaving quality null always selects the default of the
 * current format, so switching format never reuses a PNG level as a JPEG
 * quality. An explicit quality must be valid for the format.
 *
 * JPEG has no alpha channel: transparent pixels are flattened onto
 * $jpegBackground (white by default) before encoding.
 */
final class ExportOptions
{
    public const FORMAT_PNG = 'png';
    public const FORMAT_JPEG = 'jpeg';
    public const FORMAT_WEBP = 'webp';

    public const PUBLISH_IN_PLACE = 'in_place';
    public const PUBLISH_GENERATION = 'generation';

    public const DEFAULT_PNG_COMPRESSION = 9;
    public const DEFAULT_JPEG_QUALITY = 85;
    public const DEFAULT_WEBP_QUALITY = 80;

    public const DEFAULT_PATTERN = 'piece_{row}_{col}.png';
    public const DEFAULT_MANIFEST_FILENAME = 'manifest.json';

    private const EXTENSIONS = [
        self::FORMAT_PNG => ['png'],
        self::FORMAT_JPEG => ['jpg', 'jpeg'],
        self::FORMAT_WEBP => ['webp'],
    ];

    /** @var array{0: int, 1: int, 2: int} */
    private readonly array $jpegBackground;

    /**
     * @param ?callable(Piece, string): void $onPieceBeforeSave Invoked before each piece is encoded (receives the final destination path; the file is not published yet).
     * @param ?callable(Piece, string): void $onPieceAfterSave Invoked after the piece file is published at its final path.
     * @param ?callable(array<string, mixed>): array<string, mixed> $onManifestBuild Receives the manifest; may only change its "extensions" object.
     * @param array{0: int, 1: int, 2: int}|null $jpegBackground RGB background used to flatten transparency for JPEG
     */
    public function __construct(
        private readonly string $format = self::FORMAT_PNG,
        private readonly ?int $quality = null,
        private readonly string $filenamePattern = self::DEFAULT_PATTERN,
        private readonly bool $overwrite = true,
        private readonly string $manifestFilename = self::DEFAULT_MANIFEST_FILENAME,
        private readonly mixed $onPieceBeforeSave = null,
        private readonly mixed $onPieceAfterSave = null,
        private readonly mixed $onManifestBuild = null,
        ?array $jpegBackground = null,
        private readonly string $publishMode = self::PUBLISH_IN_PLACE,
        private readonly bool $includePaths = true,
    ) {
        if (!isset(self::EXTENSIONS[$format])) {
            throw new InvalidArgumentException("Unsupported export format: {$format}. Use png, jpeg or webp.");
        }
        if ($quality !== null) {
            [$min, $max] = $format === self::FORMAT_PNG ? [0, 9] : [0, 100];
            if ($quality < $min || $quality > $max) {
                throw new InvalidArgumentException("Quality {$quality} is invalid for {$format}; expected {$min}..{$max}.");
            }
        }
        self::validateFilename('filenamePattern', $filenamePattern);
        self::validateFilename('manifestFilename', $manifestFilename);
        if (strtolower(pathinfo($manifestFilename, PATHINFO_EXTENSION)) !== 'json') {
            throw new InvalidArgumentException('manifestFilename must end with .json.');
        }
        foreach (['onPieceBeforeSave' => $onPieceBeforeSave, 'onPieceAfterSave' => $onPieceAfterSave, 'onManifestBuild' => $onManifestBuild] as $name => $callback) {
            if ($callback !== null && !is_callable($callback)) {
                throw new InvalidArgumentException("{$name} must be callable or null.");
            }
        }
        $jpegBackground ??= [255, 255, 255];
        if (!array_is_list($jpegBackground) || count($jpegBackground) !== 3) {
            throw new InvalidArgumentException('jpegBackground must be an [r, g, b] list.');
        }
        foreach ($jpegBackground as $channel) {
            if (!is_int($channel) || $channel < 0 || $channel > 255) {
                throw new InvalidArgumentException('jpegBackground channels must be integers 0..255.');
            }
        }
        $this->jpegBackground = $jpegBackground;
        if (!in_array($publishMode, [self::PUBLISH_IN_PLACE, self::PUBLISH_GENERATION], true)) {
            throw new InvalidArgumentException("Unknown publish mode: {$publishMode}.");
        }
    }

    private static function validateFilename(string $paramName, string $value): void
    {
        if ($value === '' || $value === '.' || $value === '..') {
            throw new InvalidArgumentException("{$paramName} must be a valid filename, not empty and not '.' or '..'.");
        }
        if (str_contains($value, '/') || str_contains($value, '\\')) {
            throw new InvalidArgumentException("{$paramName} must not contain path separators.");
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException("{$paramName} must not contain NUL or control characters.");
        }
        if (strlen($value) > 200) {
            throw new InvalidArgumentException("{$paramName} is too long.");
        }
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Effective quality for the current format (PNG compression level or JPEG/WebP quality).
     */
    public function getQuality(): int
    {
        return $this->quality ?? match ($this->format) {
            self::FORMAT_JPEG => self::DEFAULT_JPEG_QUALITY,
            self::FORMAT_WEBP => self::DEFAULT_WEBP_QUALITY,
            default => self::DEFAULT_PNG_COMPRESSION,
        };
    }

    public function hasExplicitQuality(): bool
    {
        return $this->quality !== null;
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

    /** @return ?callable(Piece, string): void */
    public function getOnPieceBeforeSave(): ?callable
    {
        return $this->onPieceBeforeSave;
    }

    /** @return ?callable(Piece, string): void */
    public function getOnPieceAfterSave(): ?callable
    {
        return $this->onPieceAfterSave;
    }

    /** @return ?callable(array<string, mixed>): array<string, mixed> */
    public function getOnManifestBuild(): ?callable
    {
        return $this->onManifestBuild;
    }

    /** @return array{0: int, 1: int, 2: int} */
    public function getJpegBackground(): array
    {
        return $this->jpegBackground;
    }

    public function getPublishMode(): string
    {
        return $this->publishMode;
    }

    public function getIncludePaths(): bool
    {
        return $this->includePaths;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function with(array $changes): self
    {
        $args = [
            'format' => $this->format,
            'quality' => $this->quality,
            'filenamePattern' => $this->filenamePattern,
            'overwrite' => $this->overwrite,
            'manifestFilename' => $this->manifestFilename,
            'onPieceBeforeSave' => $this->onPieceBeforeSave,
            'onPieceAfterSave' => $this->onPieceAfterSave,
            'onManifestBuild' => $this->onManifestBuild,
            'jpegBackground' => $this->jpegBackground,
            'publishMode' => $this->publishMode,
            'includePaths' => $this->includePaths,
        ];

        return new self(...array_merge($args, $changes));
    }

    /**
     * Change the format. Quality scales differ per format, so an explicit
     * quality is reset to the new format's default; set it again afterwards.
     */
    public function withFormat(string $format): self
    {
        $quality = $this->quality;
        if ($quality !== null && $format !== $this->format) {
            $quality = null;
        }

        return $this->with(['format' => $format, 'quality' => $quality]);
    }

    public function withQuality(?int $quality): self
    {
        return $this->with(['quality' => $quality]);
    }

    public function withFilenamePattern(string $filenamePattern): self
    {
        return $this->with(['filenamePattern' => $filenamePattern]);
    }

    public function withOverwrite(bool $overwrite): self
    {
        return $this->with(['overwrite' => $overwrite]);
    }

    public function withManifestFilename(string $manifestFilename): self
    {
        return $this->with(['manifestFilename' => $manifestFilename]);
    }

    /** @param ?callable(Piece, string): void $callback */
    public function withOnPieceBeforeSave(?callable $callback): self
    {
        return $this->with(['onPieceBeforeSave' => $callback]);
    }

    /** @param ?callable(Piece, string): void $callback */
    public function withOnPieceAfterSave(?callable $callback): self
    {
        return $this->with(['onPieceAfterSave' => $callback]);
    }

    /** @param ?callable(array<string, mixed>): array<string, mixed> $callback */
    public function withOnManifestBuild(?callable $callback): self
    {
        return $this->with(['onManifestBuild' => $callback]);
    }

    /** @param array{0: int, 1: int, 2: int} $rgb */
    public function withJpegBackground(array $rgb): self
    {
        return $this->with(['jpegBackground' => $rgb]);
    }

    public function withPublishMode(string $mode): self
    {
        return $this->with(['publishMode' => $mode]);
    }

    public function withIncludePaths(bool $include): self
    {
        return $this->with(['includePaths' => $include]);
    }

    /**
     * Resolve a piece filename. Placeholders: {row}, {col}, {id}; when $rows
     * and $cols are given also {index} (row * cols + col), {rows}, {cols}.
     * A ".png" pattern is rewritten to the extension of the selected format.
     */
    public function resolvePieceFilename(int $row, int $col, ?int $rows = null, ?int $cols = null, ?string $id = null): string
    {
        $replacements = ['{row}' => (string) $row, '{col}' => (string) $col, '{id}' => $id ?? "r{$row}c{$col}"];
        if ($rows !== null && $cols !== null) {
            $replacements['{index}'] = (string) ($row * $cols + $col);
            $replacements['{rows}'] = (string) $rows;
            $replacements['{cols}'] = (string) $cols;
        }
        $name = strtr($this->filenamePattern, $replacements);
        if ($this->format !== self::FORMAT_PNG && str_ends_with(strtolower($name), '.png')) {
            $name = substr($name, 0, -4) . '.' . self::EXTENSIONS[$this->format][0];
        }

        return $name;
    }

    /**
     * Whether a resolved filename's extension matches the selected format.
     */
    public function extensionMatchesFormat(string $filename): bool
    {
        return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::EXTENSIONS[$this->format], true);
    }
}
