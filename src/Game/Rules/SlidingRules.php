<?php

declare(strict_types=1);

namespace ImagePuzzle\Game\Rules;

use ImagePuzzle\Exception\IllegalActionException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\Doc;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\Prng;

/**
 * Sliding puzzle: one tile (rules.empty) is removed; a tile orthogonally
 * next to the gap slides into it.
 *
 * Board: {"cells": [tileId|null, ...]} in row-major cell order, exactly one
 * null. Scramble: rules.scrambleMoves legal moves from the solved board (see
 * docs/GAME_MODES.md), so it is solvable by construction. The walk length is
 * not a distance-to-solution claim; no solver ships, so hint is unavailable.
 */
final class SlidingRules extends GridRules
{
    public const MAX_SIDE = 16;
    public const MAX_SCRAMBLE_MOVES = 100000;

    public function kind(): GameKind
    {
        return GameKind::Sliding;
    }

    public function actionTypes(): array
    {
        return ['slide'];
    }

    public function capabilities(): array
    {
        return ['undo' => true, 'redo' => true, 'reset' => true, 'shuffle' => true, 'hint' => false];
    }

    public function validateDefinition(GameDefinition $definition): void
    {
        $this->validateGrid($definition, 2, 2, self::MAX_SIDE);
        Doc::keys($definition->rules, ['empty', 'scrambleMoves'], 'definition.rules');
        $empty = Doc::id($definition->rules['empty'] ?? null, 'definition.rules.empty');
        if ($definition->tile($empty) === null) {
            throw new InvalidDefinitionException("definition.rules.empty names an unknown tile \"{$empty}\".");
        }
        Doc::int($definition->rules, 'scrambleMoves', 'definition.rules', 1, self::MAX_SCRAMBLE_MOVES);
    }

    public function solvedBoard(GameDefinition $definition): array
    {
        $empty = self::emptyTile($definition);

        return ['cells' => array_map(static fn (string $id): ?string => $id === $empty ? null : $id, self::tileIds($definition))];
    }

    public function scramble(GameDefinition $definition, int $seed): array
    {
        $rng = new Prng($seed, 'image-puzzle/scramble/sliding');
        $board = $this->solvedBoard($definition);
        $solved = $board;
        $moves = Doc::int($definition->rules, 'scrambleMoves', 'definition.rules', 1, self::MAX_SCRAMBLE_MOVES);
        $previous = null;
        // Keep walking past the requested length while the walk sits on the solved board.
        $limit = $moves + 4 * count($definition->tiles);
        for ($step = 0; $step < $limit && ($step < $moves || $board === $solved); $step++) {
            $candidates = array_values(array_filter(
                $this->legalTiles($definition, $board),
                static fn (string $id): bool => $id !== $previous,
            ));
            $previous = $candidates[$rng->int(count($candidates))];
            $board = $this->apply($definition, $board, ['type' => 'slide', 'tile' => $previous]);
        }
        if ($board === $solved) {
            throw new InvalidDefinitionException('Could not produce an unsolved sliding scramble.');
        }

        return $board;
    }

    public function validateBoard(GameDefinition $definition, mixed $board): array
    {
        $board = Doc::object($board, 'board');
        Doc::keys($board, ['cells'], 'board');
        $cells = Doc::list($board['cells'] ?? null, 'board.cells', GameDefinition::MAX_TILES);
        $count = count($definition->tiles);
        if (count($cells) !== $count) {
            throw new InvalidDefinitionException("board.cells must have {$count} entries, got " . count($cells) . '.');
        }
        $empty = self::emptyTile($definition);
        $ids = array_values(array_filter(self::tileIds($definition), static fn (string $id): bool => $id !== $empty));
        $gaps = array_keys(array_filter($cells, static fn (mixed $cell): bool => $cell === null));
        if (count($gaps) !== 1) {
            throw new InvalidDefinitionException('board.cells must contain exactly one empty cell (null).');
        }
        $filled = array_values(array_filter($cells, static fn (mixed $cell): bool => $cell !== null));
        self::permutation($filled, $ids, 'board.cells');
        $normalized = ['cells' => array_map(static fn (mixed $cell): ?string => is_string($cell) ? $cell : null, $cells)];
        if (!$this->reachable($definition, $normalized['cells'])) {
            throw new InvalidDefinitionException('board.cells is not reachable by legal slides from the solved board.');
        }

        return $normalized;
    }

    public function apply(GameDefinition $definition, array $board, array $action): array
    {
        $this->actionOf($action, ['tile']);
        $tile = Doc::id($action['tile'] ?? null, 'action.tile');
        $cells = self::cells($board);
        $from = array_search($tile, $cells, true);
        if ($from === false) {
            throw new IllegalActionException("Tile \"{$tile}\" is not on the board.");
        }
        $gap = self::gap($cells);
        if (!in_array($from, $this->neighbours($definition, $gap), true)) {
            throw new IllegalActionException("Tile \"{$tile}\" is not next to the empty cell.");
        }
        $cells[$gap] = $tile;
        $cells[$from] = null;

        return ['cells' => $cells];
    }

    public function legalActions(GameDefinition $definition, array $board): array
    {
        return array_map(static fn (string $id): array => ['type' => 'slide', 'tile' => $id], $this->legalTiles($definition, $board));
    }

    public function isComplete(GameDefinition $definition, array $board): bool
    {
        return self::cells($board) === self::cells($this->solvedBoard($definition));
    }

    public function progress(GameDefinition $definition, array $board): float
    {
        $cells = self::cells($board);
        $solved = self::cells($this->solvedBoard($definition));
        $correct = 0;
        foreach ($cells as $i => $id) {
            if ($id !== null && $id === $solved[$i]) {
                $correct++;
            }
        }

        return $correct / max(1, count($cells) - 1);
    }

    public function hint(GameDefinition $definition, array $board): ?array
    {
        return null;
    }

    /**
     * Tiles that may slide, in ascending cell order.
     *
     * @param array<string, mixed> $board
     * @return list<string>
     */
    private function legalTiles(GameDefinition $definition, array $board): array
    {
        $cells = self::cells($board);
        $out = [];
        foreach ($this->neighbours($definition, self::gap($cells)) as $cell) {
            $id = $cells[$cell];
            if ($id !== null) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * Orthogonal neighbours in ascending index order: up, left, right, down.
     *
     * @return list<int>
     */
    private function neighbours(GameDefinition $definition, int $cell): array
    {
        $cols = $definition->cols;
        $row = intdiv($cell, $cols);
        $col = $cell % $cols;
        $out = [];
        if ($row > 0) {
            $out[] = $cell - $cols;
        }
        if ($col > 0) {
            $out[] = $cell - 1;
        }
        if ($col < $cols - 1) {
            $out[] = $cell + 1;
        }
        if ($row < $definition->rows - 1) {
            $out[] = $cell + $cols;
        }

        return $out;
    }

    /**
     * A configuration is reachable exactly when the parity of the cell
     * permutation (gap counted as the removed tile) equals the parity of the
     * gap's taxicab distance from the removed tile's home.
     *
     * @param list<string|null> $cells
     */
    private function reachable(GameDefinition $definition, array $cells): bool
    {
        $empty = self::emptyTile($definition);
        $home = [];
        foreach ($definition->tiles as $tile) {
            $home[$tile->id] = $tile->home;
        }
        $perm = array_map(static fn (?string $id): int => $home[$id ?? $empty], $cells);
        $visited = [];
        $transpositions = 0;
        foreach ($perm as $start => $_) {
            if (isset($visited[$start])) {
                continue;
            }
            $length = 0;
            for ($i = $start; !isset($visited[$i]); $i = $perm[$i]) {
                $visited[$i] = true;
                $length++;
            }
            $transpositions += $length - 1;
        }
        $gap = self::gap($cells);
        $target = $home[$empty];
        $distance = abs(intdiv($gap, $definition->cols) - intdiv($target, $definition->cols)) + abs($gap % $definition->cols - $target % $definition->cols);

        return $transpositions % 2 === $distance % 2;
    }

    private static function emptyTile(GameDefinition $definition): string
    {
        return Doc::id($definition->rules['empty'] ?? null, 'definition.rules.empty');
    }

    /**
     * @param array<string, mixed> $board
     * @return list<string|null>
     */
    private static function cells(array $board): array
    {
        $out = [];
        foreach (Doc::list($board['cells'] ?? null, 'board.cells', GameDefinition::MAX_TILES) as $cell) {
            $out[] = $cell === null ? null : Doc::stringValue($cell, 'board.cells[]', 32);
        }

        return $out;
    }

    /**
     * @param list<string|null> $cells
     */
    private static function gap(array $cells): int
    {
        $gap = array_search(null, $cells, true);
        if (!is_int($gap)) {
            throw new InvalidDefinitionException('board.cells has no empty cell.');
        }

        return $gap;
    }
}
