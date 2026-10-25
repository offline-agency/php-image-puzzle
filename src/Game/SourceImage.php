<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\ImageLoader;
use ImagePuzzle\ImageLoaderInterface;

/**
 * Dimensions and content digest of the image a definition is bound to.
 */
final readonly class SourceImage
{
    public function __construct(
        public int $width,
        public int $height,
        public ?string $sha256 = null,
    ) {
        if ($width < 1 || $height < 1) {
            throw new InvalidArgumentException('Source image dimensions must be positive.');
        }
        if ($sha256 !== null && preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('Source digest must be 64 lowercase hex digits.');
        }
    }

    /**
     * Reads the header (no full decode) and hashes the file contents. The
     * dimensions are those the loader returns (EXIF orientation aware).
     */
    public static function fromFile(string $path, ?ImageLoaderInterface $loader = null): self
    {
        $info = ($loader ?? new ImageLoader())->inspect($path);
        $digest = hash_file('sha256', $path);
        if ($digest === false) {
            throw new InvalidArgumentException("Cannot read {$path}.");
        }

        return new self($info->outputWidth(), $info->outputHeight(), $digest);
    }
}
