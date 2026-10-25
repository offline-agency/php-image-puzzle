<?php

declare(strict_types=1);

return [
    /*
     * Puzzles playable through the default ConfigPuzzleRepository:
     * key => absolute path of a definition JSON file (for example the
     * definition.json written by ImagePuzzle\Game\DefinitionBuilder). Bind
     * your own ImagePuzzle\Livewire\Contracts\PuzzleRepository to resolve
     * puzzles from a database or storage disk instead.
     */
    'puzzles' => [],

    /*
     * URL prefix and middleware of the package routes (player script,
     * definitions and assets). Definitions and assets are authorized with the
     * "image-puzzle.play" gate, so the session middleware must run.
     */
    'route_prefix' => 'image-puzzle',
    'middleware' => ['web'],

    /*
     * Milliseconds between the last change and the server save. Completion
     * and leaving the page save immediately.
     */
    'sync_debounce_ms' => 2000,
];
