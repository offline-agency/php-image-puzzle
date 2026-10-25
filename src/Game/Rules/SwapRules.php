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
 * Swap puzzle: any two tiles exchange cells.
 *
 * Board: {"cells": [tileId, ...]} in row-major cell order. Completion
 * compares stable tile IDs, never pixels, so repeated image colours cannot
 * produce a false (or missed) completion.
 */
final class SwapRules extends GridRules
{
    public const MAX_SIDE = 16;

    public function kind(): GameKind
    {
        return GameKind::Swap;
    }

    public function actionTypes(): array
    {
        return ['swap'];
    }

    public function capabilities(): array
    {
        return ['undo' => true, 'redo' => true, 'reset' => true, 'shuffle' => true, 'hint' => true];
    }

    public function validateDefinition(GameDefinition $definition): void
    {
        $this->validateGrid($definition, 1, 1, self::MAX_SIDE);
        Doc::keys($definition->rules, [], 'definition.rules');
    }

    public function solvedBoard(GameDefinition $definition): array
    {
        return ['cells' => self::tileIds($definition)];
    }

    public function scramble(GameDefinition $definition, int $seed): array
    {
        return ['cells' => self::shuffledPermutation(self::tileIds($definition), new Prng($seed, 'image-puzzle/scramble/swap'))];
    }

    public function validateBoard(GameDefinition $definition, mixed $board): array
    {
        $board = Doc::object($board, 'board');
        Doc::keys($board, ['cells'], 'board');

        return ['cells' => self::permutation($board['cells'] ?? null, self::tileIds($definition), 'board.cells')];
    }

    public function apply(GameDefinition $definition, array $board, array $action): array
    {
        $this->actionOf($action, ['a', 'b']);
        $a = Doc::id($action['a'] ?? null, 'action.a');
        $b = Doc::id($action['b'] ?? null, 'action.b');
        if ($a === $b) {
            throw new IllegalActionException('A swap needs two different tiles.');
        }
        $cells = self::stringList($board, 'cells');
        $i = array_search($a, $cells, true);
        $j = array_search($b, $cells, true);
        if ($i === false || $j === false) {
            throw new IllegalActionException('Both tiles of a swap must be on the board.');
        }
        [$cells[$i], $cells[$j]] = [$cells[$j], $cells[$i]];

        return ['cells' => $cells];
    }

    public function legalActions(GameDefinition $definition, array $board): array
    {
        $cells = self::stringList($board, 'cells');
        $out = [];
        $count = count($cells);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $out[] = ['type' => 'swap', 'a' => $cells[$i], 'b' => $cells[$j]];
            }
        }

        return $out;
    }

    public function isComplete(GameDefinition $definition, array $board): bool
    {
        return self::stringList($board, 'cells') === self::tileIds($definition);
    }

    public function progress(GameDefinition $definition, array $board): float
    {
        $cells = self::stringList($board, 'cells');
        $ids = self::tileIds($definition);

        return count(array_filter($cells, static fn (string $id, int $i): bool => $id === $ids[$i], ARRAY_FILTER_USE_BOTH)) / max(1, count($ids));
    }

    /**
     * Brings the correct tile into the first wrong cell.
     */
    public function hint(GameDefinition $definition, array $board): ?array
    {
        $cells = self::stringList($board, 'cells');
        foreach (self::tileIds($definition) as $i => $id) {
            if (!isset($cells[$i])) {
                throw new InvalidDefinitionException('board.cells is incomplete.');
            }
            if ($cells[$i] !== $id) {
                return ['type' => 'swap', 'a' => $cells[$i], 'b' => $id];
            }
        }

        return null;
    }
}
