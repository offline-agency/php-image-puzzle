<?php

declare(strict_types=1);

return [
    /*
     * Eloquent models whose records hold a puzzle (they implement
     * ImagePuzzle\Filament\Contracts\PuzzleRecord, usually through the
     * HasImagePuzzle trait), by alias. A record is played and served under the
     * key "<alias>.<primary key>", for example "puzzle.12".
     */
    'models' => [],

    /*
     * Local directory that receives generated puzzles. Every record gets its
     * own subdirectory derived from its alias and key on the server, and every
     * generation a new immutable subdirectory inside it.
     */
    'directory' => storage_path('app/image-puzzles'),

    /*
     * Run generation in a queued job (true) or during the request (false).
     * Queued generation reports progress through the record's status column.
     */
    'queue' => false,
    'queue_connection' => null,
    'queue_name' => null,

    /*
     * Resource budgets of the core generator (ImagePuzzle\ResourceLimits);
     * null keeps the library default.
     */
    'limits' => [
        'max_input_bytes' => null,
        'max_pixels' => null,
        'max_pieces' => null,
    ],
];
