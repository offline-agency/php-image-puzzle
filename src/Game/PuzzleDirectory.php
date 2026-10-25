<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Geometry\Bounds;
use ImagePuzzle\Support\ErrorCapture;

/**
 * A built puzzle on disk: definition.json and the files it references, as
 * DefinitionBuilder writes them. Every referenced path is resolved inside the
 * directory (no dot segments, no symlink leaving it), so exporters and the
 * bundler never read a file the definition does not own.
 */
final readonly class PuzzleDirectory
{
    private function __construct(
        public string $path,
        public GameDefinition $definition,
    ) {
    }

    public static function open(string $path): self
    {
        $real = ErrorCapture::run(static fn (): string|false => realpath($path));
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException("Not a puzzle directory: {$path}");
        }
        $file = $real . '/' . DefinitionBuilder::DEFINITION_FILE;
        if (!is_file($file) || is_link($file)) {
            throw new InvalidArgumentException('No ' . DefinitionBuilder::DEFINITION_FILE . " in {$path}.");
        }
        $size = (int) filesize($file);
        if ($size > GameDefinition::MAX_JSON_BYTES) {
            throw new InvalidDefinitionException('Game definition exceeds ' . GameDefinition::MAX_JSON_BYTES . ' bytes.');
        }
        $json = ErrorCapture::run(static fn (): string|false => file_get_contents($file));
        if ($json === false) {
            throw new InvalidArgumentException("Cannot read {$file}.");
        }

        return new self($real, GameDefinition::fromJson($json));
    }

    /** Whether the source image is a local file (not an https URL). */
    public function hasLocalImage(): bool
    {
        return !str_starts_with($this->definition->image, 'https://');
    }

    /**
     * Absolute path of a file referenced by the definition.
     */
    public function file(string $relative): string
    {
        if (str_starts_with($relative, 'https://')) {
            throw new InvalidArgumentException("{$relative} is a remote URL, not a file of the puzzle.");
        }
        if (preg_match(PieceResolution::DIRECTORY_PATTERN, $relative) !== 1 || preg_match('~(^|/)\.\.?(/|$)~', $relative) === 1) {
            throw new InvalidArgumentException("Unsafe path in the definition: {$relative}");
        }
        $absolute = ErrorCapture::run(fn (): string|false => realpath($this->path . '/' . $relative));
        if ($absolute === false || !is_file($absolute)) {
            throw new InvalidArgumentException("Missing file referenced by the definition: {$relative}");
        }
        if (!str_starts_with($absolute, $this->path . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException("{$relative} resolves outside the puzzle directory.");
        }

        return $absolute;
    }

    /**
     * Every local file the definition references, relative and sorted:
     * source image, tile images, resolution sets and atlas.
     *
     * @return list<string>
     */
    public function referencedFiles(): array
    {
        $definition = $this->definition;
        $files = $this->hasLocalImage() ? [$definition->image] : [];
        foreach ($definition->tiles as $tile) {
            if (!isset($tile->attributes['image'])) {
                continue;
            }
            $image = Doc::stringValue($tile->attributes['image'], "tile {$tile->id} image", 255);
            $files[] = $image;
            foreach ($definition->pieceImages->resolutions as $resolution) {
                $files[] = $resolution->directory . '/' . basename($image);
            }
        }
        if ($definition->pieceImages->atlas !== null) {
            $files[] = $definition->pieceImages->atlas->image;
        }
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * Outline of every tile in source pixels: the jigsaw path, or the tile
     * rectangle for grid modes. Keys are tile IDs, in tile order.
     *
     * @return array<string, list<array{0: float, 1: float}>>
     */
    public function outlines(): array
    {
        if (in_array($this->definition->kind, [GameKind::Nonogram, GameKind::Memory], true)) {
            // A nonogram's picture is its solution; memory cards come in identical
            // pairs that one cut sheet cannot hold.
            throw new InvalidArgumentException("A {$this->definition->kind->value} puzzle has no pieces to cut; play it in the browser or export a bundle.");
        }
        $outlines = [];
        foreach ($this->definition->tiles as $tile) {
            $path = $tile->attributes['path'] ?? null;
            $outlines[$tile->id] = is_string($path) ? self::parsePath($path, $tile->id) : self::rectangle($tile->rect);
        }

        return $outlines;
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private static function rectangle(Bounds $rect): array
    {
        return [
            [(float) $rect->x, (float) $rect->y],
            [(float) ($rect->x + $rect->width), (float) $rect->y],
            [(float) ($rect->x + $rect->width), (float) ($rect->y + $rect->height)],
            [(float) $rect->x, (float) ($rect->y + $rect->height)],
        ];
    }

    /**
     * Reads the "M x y L x y ... Z" outlines that PieceShape::svgPath() writes.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function parsePath(string $path, string $id): array
    {
        if (preg_match_all('/([ML])\s*(-?[0-9]+(?:\.[0-9]+)?)\s+(-?[0-9]+(?:\.[0-9]+)?)/', $path, $matches, PREG_SET_ORDER) < 3) {
            throw new InvalidDefinitionException("Tile {$id} has an unreadable outline.");
        }
        $points = [];
        foreach ($matches as $match) {
            $points[] = [(float) $match[2], (float) $match[3]];
        }

        return $points;
    }
}
