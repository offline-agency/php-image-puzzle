<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Schemas\Components;

use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;
use ImagePuzzle\Filament\PuzzleModels;
use ImagePuzzle\Livewire\Puzzles;

/**
 * Shows the generation status of the schema's record and, once generated,
 * plays its puzzle with the Livewire player (authorized like any other
 * play). Polls while a queued generation is pending. Works in forms and
 * infolists.
 */
class PuzzlePreview extends Component
{
    protected string $view = 'image-puzzle-filament::schemas.puzzle-preview';

    protected string | \Closure | null $locale = null;

    final public function __construct()
    {
    }

    public static function make(): static
    {
        $static = new static();
        $static->configure();

        return $static;
    }

    public function locale(string | \Closure | null $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLocale(): string
    {
        $locale = $this->evaluate($this->locale);

        return is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
    }

    /**
     * @return array{key: string|null, status: string|null, error: string|null, definitionId: string|null, playable: bool}
     */
    public function getPuzzleState(): array
    {
        $record = $this->getRecord();
        if (!$record instanceof Model || !$record instanceof PuzzleRecord || !$record->exists) {
            return ['key' => null, 'status' => null, 'error' => null, 'definitionId' => null, 'playable' => false];
        }
        $key = app(PuzzleModels::class)->keyFor($record);
        $stored = app(Puzzles::class)->playable($key);

        return [
            'key' => $key,
            'status' => $record->imagePuzzleStatus(),
            'error' => $record->imagePuzzleError(),
            'definitionId' => $stored?->definition->id,
            'playable' => $stored !== null,
        ];
    }
}
