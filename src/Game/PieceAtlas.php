<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Geometry\Bounds;

/**
 * A spritesheet holding every jigsaw piece image: one request instead of one
 * per piece. Each frame is the rectangle of one piece's rendered box in the
 * atlas image, in atlas pixels; a player stretches it over the piece's
 * rendered box in source pixels, so hit areas and outlines do not change.
 */
final readonly class PieceAtlas
{
    public const MAX_SIZE = 16384;

    /**
     * @param array<string, Bounds> $frames by tile ID, in tile order
     */
    public function __construct(
        public string $image,
        public int $width,
        public int $height,
        public array $frames,
    ) {
        if (strlen($image) > 255 || preg_match(PieceResolution::DIRECTORY_PATTERN, $image) !== 1) {
            throw new InvalidDefinitionException('media.atlas.image must be a safe relative path.');
        }
        if ($width < 1 || $height < 1 || $width > self::MAX_SIZE || $height > self::MAX_SIZE) {
            throw new InvalidDefinitionException('media.atlas must be between 1 and ' . self::MAX_SIZE . " pixels per side, got {$width}x{$height}.");
        }
        $atlas = new Bounds(0, 0, $width, $height);
        foreach ($frames as $id => $frame) {
            if (!$atlas->contains($frame)) {
                throw new InvalidDefinitionException("media.atlas frame {$id} lies outside the {$width}x{$height} atlas.");
            }
        }
    }

    public static function fromArray(mixed $value, string $path): self
    {
        $doc = Doc::object($value, $path);
        Doc::keys($doc, ['image', 'width', 'height', 'frames'], $path);
        $frames = [];
        foreach (Doc::object($doc['frames'] ?? null, "{$path}.frames") as $id => $frame) {
            $frames[Doc::id((string) $id, "{$path}.frames")] = Tile::rect($frame, "{$path}.frames.{$id}");
        }

        return new self(
            Doc::string($doc, 'image', $path, 255),
            Doc::positive($doc, 'width', $path, self::MAX_SIZE),
            Doc::positive($doc, 'height', $path, self::MAX_SIZE),
            $frames,
        );
    }

    /**
     * @return array{image: string, width: int, height: int, frames: array<string, array{x: int, y: int, width: int, height: int}>}
     */
    public function toArray(): array
    {
        return [
            'image' => $this->image,
            'width' => $this->width,
            'height' => $this->height,
            'frames' => array_map(static fn (Bounds $frame): array => $frame->toArray(), $this->frames),
        ];
    }
}
