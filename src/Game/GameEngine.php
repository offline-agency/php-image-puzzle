<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\IllegalActionException;

/**
 * Server-side state transitions, identical to the TypeScript engine's
 * (packages/engine). Use it to validate submitted moves or replay a game;
 * the browser does not need a server to play.
 */
final class GameEngine
{
    public function start(GameDefinition $definition): GameState
    {
        return new GameState($definition->id, $definition->kind, $definition->initial);
    }

    /**
     * @param array<string, mixed> $action
     */
    public function apply(GameDefinition $definition, GameState $state, array $action): GameState
    {
        $this->assertOwned($definition, $state);
        if ($state->completed) {
            throw new IllegalActionException('The puzzle is already complete.');
        }
        $board = $definition->rules()->apply($definition, $state->board, $action);

        return $this->advance($definition, $state, $board, self::push($state->past, $state->board), []);
    }

    public function undo(GameDefinition $definition, GameState $state): GameState
    {
        $this->assertOwned($definition, $state);
        if (!$definition->rules()->capabilities()['undo'] || $state->past === [] || $state->completed) {
            throw new IllegalActionException('Nothing to undo.');
        }
        $past = $state->past;
        $board = array_pop($past);

        return $this->advance($definition, $state, $board, $past, self::push($state->future, $state->board));
    }

    public function redo(GameDefinition $definition, GameState $state): GameState
    {
        $this->assertOwned($definition, $state);
        if (!$definition->rules()->capabilities()['redo'] || $state->future === [] || $state->completed) {
            throw new IllegalActionException('Nothing to redo.');
        }
        $future = $state->future;
        $board = array_pop($future);

        return $this->advance($definition, $state, $board, self::push($state->past, $state->board), $future);
    }

    /**
     * Back to the definition's initial scramble with fresh counters.
     */
    public function reset(GameDefinition $definition): GameState
    {
        return $this->start($definition);
    }

    /**
     * A new deterministic scramble for $seed with fresh counters.
     */
    public function shuffle(GameDefinition $definition, int $seed): GameState
    {
        return new GameState($definition->id, $definition->kind, $definition->rules()->scramble($definition, $seed));
    }

    /**
     * @return array{action: array<string, mixed>, state: GameState}|null
     */
    public function hint(GameDefinition $definition, GameState $state): ?array
    {
        $this->assertOwned($definition, $state);
        $action = $definition->rules()->hint($definition, $state->board);
        if ($action === null) {
            return null;
        }

        return ['action' => $action, 'state' => new GameState($state->definitionId, $state->kind, $state->board, $state->moves, $state->elapsedMs, $state->hintsUsed + 1, $state->completed, $state->past, $state->future)];
    }

    /**
     * @param array<string, mixed> $board
     * @param list<array<string, mixed>> $past
     * @param list<array<string, mixed>> $future
     */
    private function advance(GameDefinition $definition, GameState $state, array $board, array $past, array $future): GameState
    {
        return new GameState(
            $state->definitionId,
            $state->kind,
            $board,
            $state->moves + 1,
            $state->elapsedMs,
            $state->hintsUsed,
            $definition->rules()->isComplete($definition, $board),
            $past,
            $future,
        );
    }

    /**
     * @param list<array<string, mixed>> $stack
     * @param array<string, mixed> $board
     * @return list<array<string, mixed>>
     */
    private static function push(array $stack, array $board): array
    {
        $stack[] = $board;

        return array_slice($stack, -GameState::HISTORY_LIMIT);
    }

    private function assertOwned(GameDefinition $definition, GameState $state): void
    {
        if ($state->definitionId !== $definition->id) {
            throw new IllegalActionException('The state belongs to another puzzle.');
        }
    }
}
