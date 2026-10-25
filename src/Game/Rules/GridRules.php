<?php

declare(strict_types=1);

namespace ImagePuzzle\Game\Rules;

use ImagePuzzle\Exception\IllegalActionException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\Doc;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\Prng;
use ImagePuzzle\Game\Tile;
use ImagePuzzle\Geometry\Bounds;

/**
 * Shared checks for modes played on a uniform rows x cols grid of tiles cut
 * from board.crop: tile i has home i (row-major) and shows cell i exactly.
 */
abstract class GridRules implements ModeRules
{
    /**
     * Uniform cell size of the definition.
     *
     * @return array{0: positive-int, 1: positive-int}
     */
    public static function cellSize(GameDefinition $definition): array
    {
        $w = intdiv($definition->crop->width, max(1, $definition->cols));
        $h = intdiv($definition->crop->height, max(1, $definition->rows));
        if ($w < 1 || $h < 1 || $w * $definition->cols !== $definition->crop->width || $h * $definition->rows !== $definition->crop->height) {
            throw new InvalidDefinitionException('board.crop must divide into equal cells.');
        }

        return [$w, $h];
    }

    public static function cellRect(GameDefinition $definition, int $index): Bounds
    {
        [$w, $h] = self::cellSize($definition);
        $row = intdiv($index, $definition->cols);
        $col = $index % $definition->cols;

        return new Bounds($definition->crop->x + $col * $w, $definition->crop->y + $row * $h, $w, $h);
    }

    protected function validateGrid(GameDefinition $definition, int $minRows, int $minCols, int $maxSide): void
    {
        $kind = $definition->kind->value;
        if ($definition->rows < $minRows || $definition->cols < $minCols || $definition->rows > $maxSide || $definition->cols > $maxSide) {
            throw new InvalidDefinitionException("A {$kind} board must be between {$minRows}x{$minCols} and {$maxSide}x{$maxSide}, got {$definition->rows}x{$definition->cols}.");
        }
        $count = $definition->rows * $definition->cols;
        if ($count < 2) {
            throw new InvalidDefinitionException("A {$kind} board needs at least two tiles.");
        }
        if (count($definition->tiles) !== $count) {
            throw new InvalidDefinitionException("A {$definition->rows}x{$definition->cols} {$kind} board needs exactly {$count} tiles, got " . count($definition->tiles) . '.');
        }
        foreach ($definition->tiles as $index => $tile) {
            if ($tile->home !== $index) {
                throw new InvalidDefinitionException("Tile {$tile->id} must have home {$index} (tiles are listed in home order).");
            }
            if ($tile->rect->toArray() !== self::cellRect($definition, $index)->toArray()) {
                throw new InvalidDefinitionException("Tile {$tile->id} does not show cell {$index} of board.crop.");
            }
            if ($tile->attributes !== []) {
                throw new InvalidDefinitionException("Tile {$tile->id} has unknown field(s): " . implode(', ', array_keys($tile->attributes)) . '.');
            }
        }
    }

    /**
     * @return list<string>
     */
    protected static function tileIds(GameDefinition $definition): array
    {
        return array_map(static fn (Tile $tile): string => $tile->id, $definition->tiles);
    }

    /**
     * Validates a list that must be a permutation of $ids.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    protected static function permutation(mixed $value, array $ids, string $path): array
    {
        $list = Doc::list($value, $path, GameDefinition::MAX_TILES);
        if (count($list) !== count($ids)) {
            throw new InvalidDefinitionException("{$path} must list " . count($ids) . ' tiles, got ' . count($list) . '.');
        }
        $known = array_flip($ids);
        $seen = [];
        $out = [];
        foreach ($list as $i => $item) {
            $id = Doc::id($item, "{$path}[{$i}]");
            if (!isset($known[$id])) {
                throw new InvalidDefinitionException("{$path}[{$i}] is an unknown tile \"{$id}\".");
            }
            if (isset($seen[$id])) {
                throw new InvalidDefinitionException("{$path}[{$i}] repeats tile \"{$id}\".");
            }
            $seen[$id] = true;
            $out[] = $id;
        }

        return $out;
    }

    /**
     * Seeded permutation that is never the identity (for at least two items).
     *
     * @param list<string> $ids
     * @return list<string>
     */
    protected static function shuffledPermutation(array $ids, Prng $rng): array
    {
        $shuffled = $rng->shuffle($ids);
        if ($shuffled === $ids && count($ids) > 1) {
            [$shuffled[0], $shuffled[1]] = [$shuffled[1], $shuffled[0]];
        }

        return $shuffled;
    }

    /**
     * @param array<string, mixed> $action
     * @param list<string> $keys
     */
    protected function actionOf(array $action, array $keys): string
    {
        $type = Doc::string($action, 'type', 'action', 32);
        if (!in_array($type, $this->actionTypes(), true)) {
            throw new IllegalActionException("The {$this->kind()->value} mode does not support \"{$type}\" actions.");
        }
        Doc::keys($action, ['type', ...$keys], 'action');

        return $type;
    }

    /**
     * @param array<string, mixed> $board
     * @return list<string>
     */
    protected static function stringList(array $board, string $key): array
    {
        $out = [];
        foreach (Doc::list($board[$key] ?? null, "board.{$key}", GameDefinition::MAX_TILES) as $item) {
            $out[] = Doc::stringValue($item, "board.{$key}[]", 32);
        }

        return $out;
    }
}
