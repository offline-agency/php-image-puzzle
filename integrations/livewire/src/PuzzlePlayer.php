<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\View;
use ImagePuzzle\Exception\ImagePuzzleException;
use ImagePuzzle\Game\GameState;
use ImagePuzzle\Livewire\Contracts\StateStore;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * <livewire:image-puzzle-player puzzle="key" />
 *
 * Gameplay runs in the browser (<image-puzzle-player>, inside wire:ignore).
 * The server resolves and authorizes the puzzle, validates every submitted
 * state against the definition with the PHP rules before storing it, and
 * dispatches `image-puzzle-completed` only for a server-verified solution.
 */
final class PuzzlePlayer extends Component
{
    #[Locked]
    public string $puzzle = '';

    #[Locked]
    public string $definitionId = '';

    /** Play stays local; saves and resets are refused. */
    #[Locked]
    public bool $readOnly = false;

    /** Bumped by server-side resets so the browser starts a fresh game. */
    #[Locked]
    public int $generation = 0;

    public ?string $locale = null;

    public bool $controls = true;

    public function mount(string $puzzle, ?string $locale = null, bool $controls = true, bool $readOnly = false): void
    {
        $this->puzzle = $puzzle;
        $this->definitionId = $this->stored()->definition->id;
        $this->locale = $locale;
        $this->controls = $controls;
        $this->readOnly = $readOnly;
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Renderless]
    public function savedState(): ?array
    {
        $stored = $this->stored();

        return $this->store()->get($this->puzzle, $stored->definition->id);
    }

    /**
     * Validates and stores a state document from the browser.
     *
     * @return array{ok: bool, error?: string}
     */
    #[Renderless]
    public function save(mixed $state): array
    {
        $stored = $this->stored();
        if ($this->readOnly) {
            throw new AccessDeniedHttpException('This player is read-only.');
        }
        $json = json_encode($state);
        if ($json === false || strlen($json) > GameState::MAX_JSON_BYTES) {
            return ['ok' => false, 'error' => 'The state document is too large or not JSON.'];
        }
        try {
            $parsed = GameState::fromJson($stored->definition, $json);
        } catch (ImagePuzzleException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $this->store()->put($this->puzzle, $parsed);
        if ($parsed->completed) {
            $this->dispatch(
                'image-puzzle-completed',
                puzzle: $this->puzzle,
                definitionId: $parsed->definitionId,
                moves: $parsed->moves,
                elapsedMs: $parsed->elapsedMs,
                hintsUsed: $parsed->hintsUsed,
            );
        }

        return ['ok' => true];
    }

    /** Discards saved progress; the browser replaces the game with a fresh one. */
    public function resetProgress(): void
    {
        $stored = $this->stored();
        if ($this->readOnly) {
            throw new AccessDeniedHttpException('This player is read-only.');
        }
        $this->store()->forget($this->puzzle, $stored->definition->id);
        $this->generation++;
    }

    public function render(): View
    {
        $this->stored();
        $debounce = app(Config::class)->get('image-puzzle.sync_debounce_ms', 2000);

        return view('image-puzzle::player', [
            'definitionUrl' => route('image-puzzle.definition', ['puzzle' => $this->puzzle]),
            'scriptUrl' => route('image-puzzle.script', ['v' => Puzzles::scriptVersion()]),
            'debounceMs' => is_int($debounce) ? max(100, $debounce) : 2000,
        ]);
    }

    /** Re-resolved and re-authorized on every request: locked props are trusted, users and grants may change. */
    private function stored(): StoredPuzzle
    {
        return app(Puzzles::class)->authorized($this->puzzle);
    }

    private function store(): StateStore
    {
        return app(StateStore::class);
    }
}
