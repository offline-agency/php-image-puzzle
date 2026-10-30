<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire;

use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Livewire\Contracts\PuzzleRepository;

/**
 * Puzzles registered in config('image-puzzle.puzzles') as
 * key => absolute path of a definition JSON file. Assets are read from the
 * file's directory.
 */
final class ConfigPuzzleRepository implements PuzzleRepository
{
    public const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    /** @var array<string, StoredPuzzle> */
    private array $loaded = [];

    /**
     * @param array<array-key, mixed> $puzzles
     */
    public function __construct(private readonly array $puzzles)
    {
    }

    public function find(string $key): ?StoredPuzzle
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return null;
        }
        if (isset($this->loaded[$key])) {
            return $this->loaded[$key];
        }
        $path = $this->puzzles[$key] ?? null;
        if (!is_string($path) || !is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        return $this->loaded[$key] = new StoredPuzzle($key, GameDefinition::fromJson($json), dirname($path));
    }
}
