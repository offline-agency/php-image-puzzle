<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Tests;

use Illuminate\Http\UploadedFile;
use ImagePuzzle\Filament\Actions\DownloadPuzzleAction;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameEngine;
use ImagePuzzle\Livewire\PuzzlePlayer;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Puzzles\Pages\CreatePuzzle;
use Workbench\App\Filament\Resources\Puzzles\Pages\EditPuzzle;
use Workbench\App\Filament\Resources\Puzzles\Pages\ViewPuzzle;
use Workbench\App\Models\Puzzle;

/**
 * The whole authoring journey in the Filament example, in one record:
 * configure (with the configurator preview), save and reload, generate,
 * play and save progress through the Livewire player, restore it on the
 * next visit, then export the generated puzzle. The browser half of the
 * same journey runs in packages/player/e2e/journey.spec.ts.
 */
final class JourneyTest extends TestCase
{
    public function testConfigurePreviewGeneratePlaySaveRestoreExport(): void
    {
        [$team] = $this->signIn();
        $config = ['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'custom', 'custom' => ['rows' => 2, 'cols' => 3], 'seed' => 11];

        // Configure with the preview, and save.
        Livewire::test(CreatePuzzle::class)
            ->assertSeeHtml('<image-puzzle-configurator')
            ->fillForm([
                'name' => 'Journey',
                'puzzle_source' => UploadedFile::fake()->createWithContent('journey.png', self::png(120, 90)),
                'puzzle_config' => $config,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $puzzle = Puzzle::query()->sole();
        self::assertSame($team->id, $puzzle->team_id);

        // Reload the saved configuration, then generate.
        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->assertFormSet(['puzzle_config' => $config])
            ->callAction('generatePuzzle')
            ->assertNotified(__('image-puzzle-filament::messages.generated'));
        $puzzle->refresh();
        $definition = GameDefinition::fromJson((string) file_get_contents((string) $puzzle->imagePuzzleDefinitionPath()));
        self::assertSame(6, count($definition->tiles));

        // Play: the view page renders the player, which saves validated progress.
        Livewire::test(ViewPuzzle::class, ['record' => $puzzle->getRouteKey()])->assertSeeHtml('<image-puzzle-player');
        $engine = new GameEngine();
        $state = $engine->start($definition);
        $action = $definition->rules()->hint($definition, $state->board) ?? $definition->rules()->legalActions($definition, $state->board)[0];
        $played = $engine->apply($definition, $state, $action)->toArray();
        $key = 'puzzle.' . $puzzle->id;
        Livewire::test(PuzzlePlayer::class, ['puzzle' => $key])->call('save', $played)->assertHasNoErrors();

        // Restore on the next visit.
        $restored = Livewire::test(PuzzlePlayer::class, ['puzzle' => $key])->call('savedState');
        $restored->assertReturned($played);

        // Export the generated puzzle with its build configuration.
        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->callAction('downloadPuzzle')
            ->assertFileDownloaded('puzzle-' . $puzzle->id . '.zip');
        $zip = (string) DownloadPuzzleAction::archive($puzzle->refresh());
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($zip));
        self::assertSame($config, json_decode((string) $archive->getFromName('build-config.json'), true));
        self::assertSame($definition->id, GameDefinition::fromJson((string) $archive->getFromName('definition.json'))->id);
        $archive->close();
        unlink($zip);
    }
}
