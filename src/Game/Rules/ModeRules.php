<?php

declare(strict_types=1);

namespace ImagePuzzle\Game\Rules;

use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;

/**
 * Pure rules of one game mode. Boards and actions are plain JSON-shaped
 * arrays so the TypeScript engine can mirror every transition; the shared
 * conformance fixtures (conformance/) are generated from these classes.
 *
 * Methods that receive a board expect one returned by validateBoard(),
 * solvedBoard(), scramble() or apply(); actions are external data and are
 * validated by apply().
 */
interface ModeRules
{
    public function kind(): GameKind;

    /**
     * @return list<string> action "type" values this mode accepts
     */
    public function actionTypes(): array;

    /**
     * @return array{undo: bool, redo: bool, reset: bool, shuffle: bool, hint: bool}
     */
    public function capabilities(): array;

    /**
     * Validates the mode-specific immutable part (board, tiles, rules).
     *
     * @throws \ImagePuzzle\Exception\InvalidDefinitionException
     */
    public function validateDefinition(GameDefinition $definition): void;

    /**
     * @return array<string, mixed>
     */
    public function solvedBoard(GameDefinition $definition): array;

    /**
     * Deterministic scramble for a seed; never returns a solved board and is
     * solvable by construction.
     *
     * @return array<string, mixed>
     */
    public function scramble(GameDefinition $definition, int $seed): array;

    /**
     * Validates an imported board and returns it normalized. Rejects
     * duplicate/missing IDs and configurations the rules cannot reach.
     *
     * @return array<string, mixed>
     *
     * @throws \ImagePuzzle\Exception\InvalidDefinitionException
     */
    public function validateBoard(GameDefinition $definition, mixed $board): array;

    /**
     * @param array<string, mixed> $board
     * @param array<string, mixed> $action
     * @return array<string, mixed> the new board; $board is never modified
     *
     * @throws \ImagePuzzle\Exception\IllegalActionException for well-formed actions the rules forbid
     * @throws \ImagePuzzle\Exception\InvalidDefinitionException for malformed actions
     */
    public function apply(GameDefinition $definition, array $board, array $action): array;

    /**
     * @param array<string, mixed> $board
     * @return list<array<string, mixed>>
     */
    public function legalActions(GameDefinition $definition, array $board): array;

    /**
     * @param array<string, mixed> $board
     */
    public function isComplete(GameDefinition $definition, array $board): bool;

    /**
     * Fraction in [0, 1] of elements in their solved position/orientation.
     *
     * @param array<string, mixed> $board
     */
    public function progress(GameDefinition $definition, array $board): float;

    /**
     * One action that makes progress, or null when the mode has no hint
     * (capabilities().hint is false) or the board is complete.
     *
     * @param array<string, mixed> $board
     * @return array<string, mixed>|null
     */
    public function hint(GameDefinition $definition, array $board): ?array;
}
