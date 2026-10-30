<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire\Contracts;

use ImagePuzzle\Livewire\StoredPuzzle;

/**
 * Resolves a puzzle reference (never a filesystem path from the browser) to
 * a validated definition and the directory holding its assets.
 */
interface PuzzleRepository
{
    /** Null when the key is unknown; authorization is checked separately. */
    public function find(string $key): ?StoredPuzzle;
}
