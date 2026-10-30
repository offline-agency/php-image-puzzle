<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire\Tests;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Session\Session;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameEngine;
use ImagePuzzle\Game\GameState;
use ImagePuzzle\Livewire\Contracts\StateStore;
use ImagePuzzle\Livewire\ImagePuzzleServiceProvider;
use ImagePuzzle\Livewire\PuzzlePlayer;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class PuzzlePlayerTest extends TestCase
{
    /**
     * A state document after $moves legal moves from the initial board.
     *
     * @return array<string, mixed>
     */
    public static function played(GameDefinition $definition, int $moves): array
    {
        $engine = new GameEngine();
        $state = $engine->start($definition);
        for ($i = 0; $i < $moves; $i++) {
            $action = $definition->rules()->legalActions($definition, $state->board)[0];
            $state = $engine->apply($definition, $state, $action);
        }

        return $state->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public static function solved(GameDefinition $definition): array
    {
        $engine = new GameEngine();
        $state = $engine->start($definition);
        for ($i = 0; $i < 500 && !$state->completed; $i++) {
            $action = $definition->rules()->hint($definition, $state->board) ?? $definition->rules()->legalActions($definition, $state->board)[0];
            $state = $engine->apply($definition, $state, $action);
        }

        return $state->toArray();
    }

    private function deny(string $puzzle = '*'): void
    {
        $this->app?->make(Gate::class)->define(ImagePuzzleServiceProvider::ABILITY, static fn (mixed $user, string $key): bool => $puzzle !== '*' && $key !== $puzzle);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function saved(string $kind): ?array
    {
        return $this->app?->make(StateStore::class)->get($kind, self::puzzle($kind)->definition->id);
    }

    public function test_renders_the_element_inside_wire_ignore_with_the_authorized_definition_url(): void
    {
        $definition = self::puzzle('swap')->definition;
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap', 'locale' => 'it', 'themeClass' => 'dark'])
            ->assertSet('definitionId', $definition->id)
            ->assertSeeHtml('wire:ignore')
            ->assertSeeHtml('<image-puzzle-player')
            ->assertSeeHtml('src="' . route('image-puzzle.definition', ['puzzle' => 'swap']) . '"')
            ->assertSeeHtml('locale="it"')
            ->assertSeeHtml('theme-class="dark"');
    }

    public function test_unknown_puzzles_are_404_and_denied_puzzles_403(): void
    {
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'nope'])->assertStatus(404);
        Livewire::test(PuzzlePlayer::class, ['puzzle' => '../etc/passwd'])->assertStatus(404);
        $this->deny();
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap'])->assertStatus(403);
    }

    public function test_the_reference_and_flags_cannot_be_changed_from_the_browser(): void
    {
        foreach (['puzzle' => 'jigsaw', 'readOnly' => false, 'definitionId' => 'x', 'generation' => 9] as $property => $value) {
            try {
                Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap', 'readOnly' => true])->set($property, $value);
                self::fail("{$property} was writable");
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_saves_a_validated_state_and_returns_it_to_the_next_visit(): void
    {
        $definition = self::puzzle('swap')->definition;
        $state = self::played($definition, 2);

        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap'])
            ->call('save', $state)
            ->assertReturned(['ok' => true])
            ->assertNotDispatched('image-puzzle-completed');

        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap'])
            ->call('savedState')
            ->assertReturned(GameState::fromArray($definition, $state)->toArray());
    }

    /**
     * @return iterable<string, array{0: \Closure(): array<string, mixed>, 1: string}>
     */
    public static function rejectedStates(): iterable
    {
        yield 'state of another puzzle' => [static fn (): array => self::played(self::puzzle('jigsaw')->definition, 1), 'definition'];
        yield 'completed flag forged' => [static fn (): array => array_merge(self::played(self::puzzle('swap')->definition, 1), ['completed' => true]), 'completed'];
        yield 'not a state document' => [static fn (): array => ['hello' => 'world'], 'state'];
        yield 'oversized' => [static fn (): array => ['blob' => str_repeat('x', GameState::MAX_JSON_BYTES)], 'too large'];
    }

    /**
     * @param \Closure(): array<string, mixed> $state
     */
    #[DataProvider('rejectedStates')]
    public function test_rejects_tampered_foreign_and_oversized_states_without_storing_them(\Closure $state, string $error): void
    {
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap'])
            ->call('save', $state())
            ->assertReturned(static fn (mixed $result): bool => is_array($result) && $result['ok'] === false && is_string($result['error'] ?? null) && str_contains($result['error'], $error));
        self::assertNull($this->saved('swap'));
    }

    public function test_dispatches_completion_only_for_a_server_verified_solution(): void
    {
        $definition = self::puzzle('swap')->definition;
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap'])
            ->call('save', self::solved($definition))
            ->assertReturned(['ok' => true])
            ->assertDispatched('image-puzzle-completed', puzzle: 'swap', definitionId: $definition->id);
    }

    public function test_read_only_players_refuse_saves_and_resets(): void
    {
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap', 'readOnly' => true])
            ->call('save', self::played(self::puzzle('swap')->definition, 1))
            ->assertStatus(403);
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap', 'readOnly' => true])
            ->call('resetProgress')
            ->assertStatus(403);
        self::assertNull($this->saved('swap'));
    }

    public function test_a_revoked_grant_is_enforced_on_later_requests(): void
    {
        $component = Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap']);
        $this->deny();
        $component->call('save', self::played(self::puzzle('swap')->definition, 1))->assertStatus(403);
        self::assertNull($this->saved('swap'));
    }

    public function test_reset_progress_forgets_the_save_and_replaces_the_browser_game(): void
    {
        $definition = self::puzzle('swap')->definition;
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap'])
            ->call('save', self::played($definition, 1))
            ->call('resetProgress')
            ->assertSet('generation', 1)
            ->assertSeeHtml('wire:key="image-puzzle-' . $definition->id . '-1"');
        self::assertNull($this->saved('swap'));
    }

    public function test_saves_are_scoped_to_the_session(): void
    {
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap'])->call('save', self::played(self::puzzle('swap')->definition, 1));
        self::assertNotNull($this->saved('swap'));

        $this->app?->make(Session::class)->flush();
        Livewire::test(PuzzlePlayer::class, ['puzzle' => 'swap'])->call('savedState')->assertReturned(null);
    }
}
