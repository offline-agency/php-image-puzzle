<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament;

use Illuminate\Database\Eloquent\Model;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;

/**
 * Maps records to puzzle keys ("<alias>.<primary key>") and back, and
 * derives each record's generation directory on the server. Keys from a
 * browser are only ever resolved through the configured aliases.
 */
final readonly class PuzzleModels
{
    private const KEY_PATTERN = '/^([a-z][a-z0-9_-]{0,31})\.([A-Za-z0-9_-]{1,64})$/';

    /**
     * @param array<string, class-string<Model&PuzzleRecord>> $models alias => model class
     */
    public function __construct(
        private array $models,
        private string $directory,
    ) {
        foreach ($models as $alias => $class) {
            if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $alias) !== 1) {
                throw new \InvalidArgumentException("Invalid image puzzle model alias \"{$alias}\": use lowercase letters, digits, - and _.");
            }
            if (!is_subclass_of($class, Model::class) || !is_subclass_of($class, PuzzleRecord::class)) {
                throw new \InvalidArgumentException("{$class} must be an Eloquent model implementing " . PuzzleRecord::class . '.');
            }
        }
    }

    public function keyFor(Model&PuzzleRecord $record): string
    {
        $alias = array_search($record::class, $this->models, true);
        if (!is_string($alias)) {
            throw new \InvalidArgumentException('Register ' . $record::class . ' in image-puzzle-filament.models to play or generate its puzzle.');
        }
        $id = $record->getKey();
        if ((!is_int($id) && !is_string($id)) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string) $id) !== 1) {
            throw new \InvalidArgumentException('Puzzle records need an integer or [A-Za-z0-9_-] string primary key.');
        }

        $key = "{$alias}.{$id}";
        if (strlen($key) > 64) {
            // The player routes accept keys of at most 64 characters.
            throw new \InvalidArgumentException("The puzzle key \"{$key}\" is longer than 64 characters: use a shorter model alias.");
        }

        return $key;
    }

    /**
     * The record behind a key, or null for unknown aliases, malformed keys and missing records.
     */
    public function find(string $key): (Model&PuzzleRecord)|null
    {
        if (preg_match(self::KEY_PATTERN, $key, $match) !== 1 || !isset($this->models[$match[1]])) {
            return null;
        }
        $record = $this->models[$match[1]]::query()->whereKey($match[2])->first();

        return $record instanceof PuzzleRecord ? $record : null;
    }

    /** The registered alias a key starts with ("<alias>."), if any. */
    public function aliasOf(string $key): ?string
    {
        $dot = strpos($key, '.');
        $alias = $dot === false ? '' : substr($key, 0, $dot);

        return isset($this->models[$alias]) ? $alias : null;
    }

    /** Directory that holds every generation of a record; never derived from client input. */
    public function directoryFor(Model&PuzzleRecord $record): string
    {
        return rtrim($this->directory, '/') . '/' . str_replace('.', '/', $this->keyFor($record));
    }

    public function directory(): string
    {
        return rtrim($this->directory, '/');
    }
}
