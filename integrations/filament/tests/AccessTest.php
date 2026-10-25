<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Tests;

use Filament\Facades\Filament;
use Illuminate\Contracts\Config\Repository as Config;
use ImagePuzzle\Filament\PuzzleGeneration;
use ImagePuzzle\Filament\PuzzleModels;
use ImagePuzzle\Filament\Widgets\PuzzlePlayerWidget;
use ImagePuzzle\Livewire\PuzzlePlayer;
use Livewire\Livewire;
use Workbench\App\Models\Puzzle;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

/**
 * Record puzzles are served by the package routes, outside the panel: every
 * route must authorize the record itself, which is where the tenant check lives.
 */
final class AccessTest extends TestCase
{
    /**
     * @return array{Puzzle, string, User}
     */
    private function generatedForeignPuzzle(): array
    {
        [$globex, $owner] = $this->signIn('globex');
        $puzzle = $this->puzzleFor($globex);
        app(PuzzleGeneration::class)->generate($puzzle);
        $definition = (string) $puzzle->refresh()->imagePuzzleDefinitionPath();
        $assets = glob(dirname($definition) . '/*.{png,webp}', GLOB_BRACE);
        $asset = is_array($assets) && $assets !== [] ? basename($assets[0]) : 'source.png';

        return [$puzzle, $asset, $owner];
    }

    public function testMembersCanFetchTheirRecordsDefinitionAssetsAndSource(): void
    {
        [$puzzle, $asset, $owner] = $this->generatedForeignPuzzle();
        $key = 'puzzle.' . $puzzle->id;

        $this->actingAs($owner)->get(route('image-puzzle.definition', ['puzzle' => $key]))->assertOk();
        $this->actingAs($owner)->get(route('image-puzzle.asset', ['puzzle' => $key, 'path' => $asset]))->assertOk();
        $this->actingAs($owner)->get(route('image-puzzle-filament.source', ['puzzle' => $key]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function testOtherTenantsAndGuestsAreRejectedOnEveryRoute(): void
    {
        [$puzzle, $asset] = $this->generatedForeignPuzzle();
        $key = 'puzzle.' . $puzzle->id;
        [, $intruder] = $this->signIn('acme');
        // The package routes run outside the panel: no tenant scope hides the record there.
        Filament::setCurrentPanel(null);
        $urls = [
            route('image-puzzle.definition', ['puzzle' => $key]),
            route('image-puzzle.asset', ['puzzle' => $key, 'path' => $asset]),
            route('image-puzzle-filament.source', ['puzzle' => $key]),
        ];

        foreach ($urls as $url) {
            $this->actingAs($intruder)->get($url)->assertForbidden();
        }
        auth()->logout();
        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function testUnknownAndUnregisteredKeysAreNotFound(): void
    {
        $this->signIn();

        $this->get(route('image-puzzle-filament.source', ['puzzle' => 'puzzle.404']))->assertNotFound();
        $this->get(route('image-puzzle-filament.source', ['puzzle' => 'secret.1']))->assertNotFound();
        $this->get(route('image-puzzle.definition', ['puzzle' => 'puzzle.404']))->assertNotFound();
        $this->get('/image-puzzle/records/..%2F..%2Fetc/source')->assertNotFound();
    }

    public function testARecordKeyNeverFallsBackToAConfiguredPuzzle(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team);
        $key = 'puzzle.' . $puzzle->id;
        app(Config::class)->set('image-puzzle.puzzles', [$key => dirname(__DIR__, 3) . '/examples/player/assets/swap.json']);

        // Not generated yet: the record wins and has nothing to serve.
        $this->get(route('image-puzzle.definition', ['puzzle' => $key]))->assertNotFound();
    }

    public function testADefinitionOutsideTheRecordsDirectoryIsNotServed(): void
    {
        [$puzzle, , $owner] = $this->generatedForeignPuzzle();
        $other = $this->puzzleFor(Team::query()->where('slug', 'globex')->sole());
        app(PuzzleGeneration::class)->generate($other);
        // Point the record at another record's generation, e.g. through a tampered column.
        $puzzle->forceFill(['puzzle_definition' => $other->refresh()->puzzle_definition])->save();

        $this->actingAs($owner)->get(route('image-puzzle.definition', ['puzzle' => 'puzzle.' . $puzzle->id]))->assertNotFound();
    }

    public function testThePlayerRefusesAnotherTenantsRecord(): void
    {
        [$puzzle] = $this->generatedForeignPuzzle();
        $this->signIn('acme');
        $key = 'puzzle.' . $puzzle->id;

        // Inside the panel the tenant scope already hides the record...
        Livewire::test(PuzzlePlayer::class, ['puzzle' => $key])->assertNotFound();
        // ...and without it the record policy refuses it.
        Filament::setCurrentPanel(null);
        Livewire::test(PuzzlePlayer::class, ['puzzle' => $key])->assertForbidden();
    }

    public function testTheWidgetPlaysAConfiguredPuzzle(): void
    {
        $this->signIn();

        Livewire::test(PuzzlePlayerWidget::class, ['puzzle' => 'swap', 'heading' => 'Example puzzle'])
            ->assertSee('Example puzzle')
            ->assertSeeHtml('<image-puzzle-player');
    }

    public function testTheWidgetShowsAMessageForUnavailablePuzzles(): void
    {
        [$puzzle] = $this->generatedForeignPuzzle();
        $this->signIn('acme');

        Livewire::test(PuzzlePlayerWidget::class, ['puzzle' => 'missing'])
            ->assertSee(__('image-puzzle-filament::messages.unavailable'))
            ->assertDontSeeHtml('<image-puzzle-player');
        Livewire::test(PuzzlePlayerWidget::class, ['record' => $puzzle])
            ->assertSee(__('image-puzzle-filament::messages.unavailable'))
            ->assertDontSeeHtml('<image-puzzle-player');
    }

    public function testTheWidgetReactsOnlyToItsOwnPuzzlesCompletion(): void
    {
        $this->signIn();

        $widget = Livewire::test(PuzzlePlayerWidget::class, ['puzzle' => 'swap']);
        $widget->dispatch('image-puzzle-completed', puzzle: 'other', moves: 3);
        $widget->assertSet('completedMoves', null)->assertNotDispatched('image-puzzle-widget-completed');
        $widget->dispatch('image-puzzle-completed', puzzle: 'swap', moves: 9);
        $widget->assertSet('completedMoves', 9)
            ->assertNotified(__('image-puzzle-filament::messages.completed', ['moves' => 9]))
            ->assertDispatched('image-puzzle-widget-completed', puzzle: 'swap', moves: 9);
    }

    public function testTheWidgetPuzzleKeyCannotBeChangedFromTheBrowser(): void
    {
        $this->signIn();

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        Livewire::test(PuzzlePlayerWidget::class, ['puzzle' => 'swap'])->set('puzzle', 'puzzle.1');
    }

    public function testModelAliasesAndKeysAreValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PuzzleModels(['Bad Alias' => Puzzle::class], $this->generations);
    }

    public function testKeysLongerThanThePlayerRoutesAcceptAreRejected(): void
    {
        $models = new PuzzleModels([str_repeat('a', 32) => Puzzle::class], $this->generations);
        $puzzle = new Puzzle();
        $puzzle->forceFill(['id' => str_repeat('9', 40)]);
        $puzzle->setKeyType('string');

        $this->expectException(\InvalidArgumentException::class);
        $models->keyFor($puzzle);
    }
}
