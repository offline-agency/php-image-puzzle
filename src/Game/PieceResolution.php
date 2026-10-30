<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidDefinitionException;

/**
 * One extra set of jigsaw piece images, rendered from the same pieces at a
 * smaller scale. $width is the width of the whole source at that scale, so
 * the scale is $width / source width. Each piece keeps its ID and its file
 * name: its image in this set is "$directory/<file name of the tile image>".
 * Geometry (rects, rendered boxes, outlines) stays in source pixels.
 */
final readonly class PieceResolution
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9-]{0,15}$/';
    public const DIRECTORY_PATTERN = '~^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$~';

    public function __construct(
        public string $name,
        public int $width,
        public string $directory,
    ) {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidDefinitionException("Resolution name \"{$name}\" must match " . self::NAME_PATTERN . '.');
        }
        if ($width < 1 || $width > 1 << 20) {
            throw new InvalidDefinitionException("Resolution {$name} has an invalid width {$width}.");
        }
        if (strlen($directory) > 255 || preg_match(self::DIRECTORY_PATTERN, $directory) !== 1) {
            throw new InvalidDefinitionException("Resolution {$name} directory must be a safe relative path.");
        }
    }

    public static function fromArray(mixed $value, string $path): self
    {
        $doc = Doc::object($value, $path);
        Doc::keys($doc, ['name', 'width', 'directory'], $path);

        return new self(Doc::string($doc, 'name', $path, 16), Doc::positive($doc, 'width', $path, 1 << 20), Doc::string($doc, 'directory', $path, 255));
    }

    /**
     * A length in source pixels (a rendered box side) in pixels of this set, at least 1.
     *
     * @return int<1, max>
     */
    public function scaled(int $length, int $sourceWidth): int
    {
        return max(1, (int) round($length * $this->width / $sourceWidth));
    }

    /**
     * @return array{name: string, width: int, directory: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'width' => $this->width, 'directory' => $this->directory];
    }
}
