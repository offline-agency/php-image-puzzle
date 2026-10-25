<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Geometry\Bounds;

/**
 * One immutable playing element of a definition: a stable ID that never
 * depends on pixel colours, its solved position ("home") and the source
 * rectangle it shows. Mode-specific data (jigsaw outline, memory pair, ...)
 * lives in $attributes and is validated by the mode's rules.
 */
final readonly class Tile
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $id,
        public int $home,
        public Bounds $rect,
        public array $attributes = [],
    ) {
    }

    public static function fromArray(mixed $value, string $path): self
    {
        $tile = Doc::object($value, $path);
        $id = Doc::id($tile['id'] ?? null, "{$path}.id");
        $home = Doc::int($tile, 'home', $path, 0, 1 << 20);
        $rect = self::rect($tile['rect'] ?? null, "{$path}.rect");
        $attributes = $tile;
        unset($attributes['id'], $attributes['home'], $attributes['rect']);

        return new self($id, $home, $rect, $attributes);
    }

    public static function rect(mixed $value, string $path): Bounds
    {
        $rect = Doc::object($value, $path);
        Doc::keys($rect, ['x', 'y', 'width', 'height'], $path);
        $limit = 1 << 20;

        return new Bounds(
            Doc::int($rect, 'x', $path, -$limit, $limit),
            Doc::int($rect, 'y', $path, -$limit, $limit),
            Doc::positive($rect, 'width', $path, $limit),
            Doc::positive($rect, 'height', $path, $limit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'home' => $this->home, 'rect' => $this->rect->toArray()] + $this->attributes;
    }

    public function attribute(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            throw new InvalidDefinitionException("Tile {$this->id} has no \"{$key}\" attribute.");
        }

        return $this->attributes[$key];
    }
}
