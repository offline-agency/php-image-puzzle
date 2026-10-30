<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire\Contracts;

use ImagePuzzle\Game\GameState;

/**
 * Saved player progress. Implementations scope entries to the current owner
 * (session, user, tenant) themselves; callers never pass an owner from the
 * browser. Entries are keyed by puzzle and definition ID, so a regenerated
 * puzzle never receives a save made for an older definition.
 */
interface StateStore
{
    /**
     * @return array<string, mixed>|null the state document as saved
     */
    public function get(string $puzzle, string $definitionId): ?array;

    public function put(string $puzzle, GameState $state): void;

    public function forget(string $puzzle, string $definitionId): void;
}
