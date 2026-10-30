<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Widgets;

use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;
use ImagePuzzle\Filament\PuzzleModels;
use ImagePuzzle\Livewire\Puzzles;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * Dashboard or page widget that plays a puzzle: a configured key
 * (PuzzlePlayerWidget::make(['puzzle' => 'autumn'])) or, on a record page,
 * the record's own puzzle. Unavailable puzzles render a message instead of
 * failing the page. A server-verified completion shows a notification and
 * is re-dispatched to the page as "image-puzzle-widget-completed".
 */
class PuzzlePlayerWidget extends Widget
{
    /**
     * @var view-string
     */
    protected string $view = 'image-puzzle-filament::widgets.puzzle-player-widget';

    protected int | string | array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    #[Locked]
    public ?string $puzzle = null;

    public ?string $heading = null;

    public ?Model $record = null;

    public ?int $completedMoves = null;

    public function getPuzzleKey(): ?string
    {
        if ($this->puzzle !== null && $this->puzzle !== '') {
            return $this->puzzle;
        }

        return $this->record instanceof PuzzleRecord ? app(PuzzleModels::class)->keyFor($this->record) : null;
    }

    #[On('image-puzzle-completed')]
    public function completed(string $puzzle, int $moves): void
    {
        if ($puzzle !== $this->getPuzzleKey()) {
            return;
        }
        $this->completedMoves = $moves;
        Notification::make()
            ->success()
            ->title(__('image-puzzle-filament::messages.completed', ['moves' => $moves]))
            ->send();
        $this->dispatch('image-puzzle-widget-completed', puzzle: $puzzle, moves: $moves);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $key = $this->getPuzzleKey();
        $stored = $key === null ? null : app(Puzzles::class)->playable($key);

        return [
            'key' => $key,
            'definitionId' => $stored?->definition->id,
            'heading' => $this->heading ?? __('image-puzzle-filament::messages.widget_heading'),
        ];
    }
}
