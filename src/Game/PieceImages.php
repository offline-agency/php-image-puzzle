<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidDefinitionException;

/**
 * Optional alternative images of jigsaw pieces, stored in definition.media:
 * smaller resolutions ("resolutions") and a spritesheet ("atlas"). Every
 * alternative shows the same pieces with the same IDs over the same
 * geometry; the tile images stay the full-resolution default.
 */
final readonly class PieceImages
{
    public const MAX_RESOLUTIONS = 4;

    /**
     * @param list<PieceResolution> $resolutions
     */
    public function __construct(
        public array $resolutions = [],
        public ?PieceAtlas $atlas = null,
    ) {
        if (count($resolutions) > self::MAX_RESOLUTIONS) {
            throw new InvalidDefinitionException('media.resolutions holds at most ' . self::MAX_RESOLUTIONS . ' entries.');
        }
        $names = [];
        $directories = [];
        foreach ($resolutions as $resolution) {
            if (isset($names[$resolution->name]) || isset($directories[$resolution->directory])) {
                throw new InvalidDefinitionException("media.resolutions repeats the name or directory of \"{$resolution->name}\".");
            }
            $names[$resolution->name] = true;
            $directories[$resolution->directory] = true;
        }
    }

    /**
     * @param array<string, mixed> $media the definition.media object
     */
    public static function fromMedia(array $media): self
    {
        $resolutions = [];
        if (isset($media['resolutions'])) {
            foreach (Doc::list($media['resolutions'], 'definition.media.resolutions', self::MAX_RESOLUTIONS) as $i => $value) {
                $resolutions[] = PieceResolution::fromArray($value, "definition.media.resolutions[{$i}]");
            }
            if ($resolutions === []) {
                throw new InvalidDefinitionException('definition.media.resolutions must not be empty when present.');
            }
        }
        $atlas = isset($media['atlas']) ? PieceAtlas::fromArray($media['atlas'], 'definition.media.atlas') : null;

        return new self($resolutions, $atlas);
    }

    public function isEmpty(): bool
    {
        return $this->resolutions === [] && $this->atlas === null;
    }

    /**
     * Checks the alternatives against the definition they belong to.
     *
     * @param list<Tile> $tiles
     */
    public function validate(GameKind $kind, int $sourceWidth, array $tiles): void
    {
        if ($this->isEmpty()) {
            return;
        }
        if ($kind !== GameKind::Jigsaw) {
            throw new InvalidDefinitionException('media.resolutions and media.atlas are only defined for jigsaw pieces.');
        }
        $default = $tiles === [] ? '' : dirname(Doc::stringValue($tiles[0]->attributes['image'] ?? '', 'definition.tiles[0].image', 255));
        foreach ($this->resolutions as $resolution) {
            if ($resolution->directory === $default) {
                throw new InvalidDefinitionException("Resolution {$resolution->name} cannot use the directory of the full-resolution tile images.");
            }
            if ($resolution->width >= $sourceWidth) {
                throw new InvalidDefinitionException("Resolution {$resolution->name} ({$resolution->width} px) must be narrower than the {$sourceWidth} px source; the tile images are the full resolution.");
            }
        }
        if ($this->atlas !== null && array_keys($this->atlas->frames) !== array_map(static fn (Tile $tile): string => $tile->id, $tiles)) {
            throw new InvalidDefinitionException('media.atlas.frames must list every tile ID once, in tile order.');
        }
    }

    /**
     * @return array<string, mixed> the keys to merge into definition.media
     */
    public function toArray(): array
    {
        $media = [];
        if ($this->resolutions !== []) {
            $media['resolutions'] = array_map(static fn (PieceResolution $resolution): array => $resolution->toArray(), $this->resolutions);
        }
        if ($this->atlas !== null) {
            $media['atlas'] = $this->atlas->toArray();
        }

        return $media;
    }
}
