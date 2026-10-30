<?php

declare(strict_types=1);

namespace ImagePuzzle\Tests\Game;

use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameFactory;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\SourceImage;

final class GameSupport
{
    public static function source(int $width = 640, int $height = 480): SourceImage
    {
        return new SourceImage($width, $height, hash('sha256', "source {$width}x{$height}"));
    }

    /**
     * @param array<string, mixed> $custom
     */
    public static function definition(GameKind $kind, string $preset = 'easy', array $custom = [], int $seed = 1, ?SourceImage $source = null): GameDefinition
    {
        return (new GameFactory())->create($kind, $source ?? self::source(), 'source.png', $preset, $custom, $seed);
    }

    /**
     * Definition document with $changes merged at the top level (the id is
     * kept, so content changes must be detected).
     *
     * @param array<mixed> $changes
     * @return array<string, mixed>
     */
    public static function tampered(GameDefinition $definition, array $changes): array
    {
        $doc = json_decode($definition->toJson(), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($doc)) {
            throw new \LogicException('definition is not an object');
        }
        $out = [];
        foreach ($doc as $key => $value) {
            $out[(string) $key] = $value;
        }

        return array_replace($out, self::stringKeys($changes));
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    public static function stringKeys(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * Board entry $index of list field $key, as an array.
     *
     * @param array<string, mixed> $board
     * @return array<mixed>
     */
    public static function entry(array $board, string $key, int $index): array
    {
        $list = $board[$key] ?? null;
        $entry = is_array($list) ? ($list[$index] ?? null) : null;
        if (!is_array($entry)) {
            throw new \LogicException("board.{$key}[{$index}] is not an object");
        }

        return $entry;
    }
}
