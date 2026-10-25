<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament;

use ImagePuzzle\Exception\ImagePuzzleException;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Livewire\Contracts\PuzzleRepository;
use ImagePuzzle\Livewire\StoredPuzzle;

/**
 * Resolves "<alias>.<id>" keys to the generated puzzle of a record, with the
 * record as the authorization subject (the default gate then requires the
 * "view" policy ability). Other keys go to the previous repository.
 */
final readonly class RecordPuzzleRepository implements PuzzleRepository
{
    public function __construct(
        private PuzzleModels $models,
        private PuzzleRepository $fallback,
    ) {
    }

    public function find(string $key): ?StoredPuzzle
    {
        // Keys with a registered alias are records only: never fall back for them.
        if ($this->models->aliasOf($key) === null) {
            return $this->fallback->find($key);
        }
        $record = $this->models->find($key);
        if ($record === null) {
            return null;
        }
        $path = $record->imagePuzzleDefinitionPath();
        // Only definitions inside the record's own generation directory are served.
        $directory = $this->models->directoryFor($record) . '/';
        if ($path === null || !str_starts_with($path, $directory) || !is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }
        try {
            $definition = GameDefinition::fromJson($json);
        } catch (ImagePuzzleException) {
            return null;
        }

        return new StoredPuzzle($key, $definition, dirname($path), $record);
    }
}
