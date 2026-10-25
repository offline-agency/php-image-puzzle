<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire;

use Illuminate\Contracts\Session\Session;
use ImagePuzzle\Game\GameState;
use ImagePuzzle\Livewire\Contracts\StateStore;

/**
 * Default store: progress lives in the visitor's session, so basic play
 * needs no database and saves are never shared between visitors.
 */
final class SessionStateStore implements StateStore
{
    private const PREFIX = 'image-puzzle.state.';

    public function __construct(private readonly Session $session)
    {
    }

    public function get(string $puzzle, string $definitionId): ?array
    {
        $value = $this->session->get($this->key($puzzle, $definitionId));
        if (!is_string($value)) {
            return null;
        }
        $state = json_decode($value, true, 512);
        if (!is_array($state)) {
            return null;
        }
        $out = [];
        foreach ($state as $name => $item) {
            $out[(string) $name] = $item;
        }

        return $out;
    }

    public function put(string $puzzle, GameState $state): void
    {
        $this->session->put($this->key($puzzle, $state->definitionId), $state->toJson(0));
    }

    public function forget(string $puzzle, string $definitionId): void
    {
        $this->session->forget($this->key($puzzle, $definitionId));
    }

    private function key(string $puzzle, string $definitionId): string
    {
        return self::PREFIX . $puzzle . '.' . $definitionId;
    }
}
