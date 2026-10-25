<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Tests;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use ImagePuzzle\Filament\Actions\DownloadPuzzleAction;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;
use ImagePuzzle\Filament\GenerationError;
use ImagePuzzle\Filament\Jobs\GeneratePuzzle;
use ImagePuzzle\Filament\PuzzleGeneration;
use Livewire\Livewire;
use Workbench\App\Filament\Resources\Puzzles\Pages\EditPuzzle;
use Workbench\App\Filament\Resources\Puzzles\Pages\ViewPuzzle;

final class GenerationTest extends TestCase
{
    public function testGeneratesIntoTheRecordsOwnDirectory(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team);

        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->assertActionVisible('generatePuzzle')
            ->assertActionHidden('downloadPuzzle')
            ->callAction('generatePuzzle')
            ->assertNotified(__('image-puzzle-filament::messages.generated'))
            ->assertActionVisible('downloadPuzzle');

        $puzzle->refresh();
        self::assertSame(PuzzleRecord::STATUS_READY, $puzzle->imagePuzzleStatus());
        self::assertNull($puzzle->imagePuzzleError());
        $definition = (string) $puzzle->imagePuzzleDefinitionPath();
        self::assertFileExists($definition);
        self::assertStringStartsWith($this->generations . '/puzzle/' . $puzzle->id . '/g-', $definition);
    }

    public function testRegeneratingReplacesThePreviousGenerationOnly(): void
    {
        [$team] = $this->signIn();
        $first = $this->puzzleFor($team);
        $second = $this->puzzleFor($team);
        $generation = app(PuzzleGeneration::class);
        $generation->generate($first);
        $generation->generate($second);
        $before = dirname((string) $first->refresh()->imagePuzzleDefinitionPath());
        $other = (string) $second->refresh()->imagePuzzleDefinitionPath();

        $generation->generate($first);

        $after = (string) $first->refresh()->imagePuzzleDefinitionPath();
        self::assertNotSame($before, dirname($after));
        self::assertDirectoryDoesNotExist($before);
        self::assertFileExists($after);
        self::assertFileExists($other, "Another record's assets are never touched.");
    }

    public function testAFailedGenerationKeepsThePreviousPuzzleAndShowsTheReason(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team);
        app(PuzzleGeneration::class)->generate($puzzle);
        $previous = (string) $puzzle->refresh()->imagePuzzleDefinitionPath();
        $puzzle->update(['puzzle_config' => self::config() + ['crop' => ['x' => 100, 'y' => 0, 'width' => 50, 'height' => 50]]]);

        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->callAction('generatePuzzle')
            ->assertNotified(__('image-puzzle-filament::messages.generation_failed'));

        $puzzle->refresh();
        self::assertSame(PuzzleRecord::STATUS_FAILED, $puzzle->imagePuzzleStatus());
        self::assertSame('The crop 50x50 at 100,0 is outside the 120x90 image.', $puzzle->imagePuzzleError());
        self::assertSame($previous, $puzzle->imagePuzzleDefinitionPath());
        self::assertFileExists($previous);
        self::assertCount(1, (array) glob($this->generations . '/puzzle/' . $puzzle->id . '/g-*'), 'The failed build left no directory behind.');
    }

    public function testGenerationWithoutASourceImageFailsRecoverably(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team, withSource: false);

        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])->callAction('generatePuzzle');

        $puzzle->refresh();
        self::assertSame(PuzzleRecord::STATUS_FAILED, $puzzle->imagePuzzleStatus());
        self::assertSame('Upload a source image before generating the puzzle.', $puzzle->imagePuzzleError());
    }

    public function testQueuedGenerationReportsProgressThroughTheRecordStatus(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team);
        app(Config::class)->set('image-puzzle-filament.queue', true);
        app(Config::class)->set('image-puzzle-filament.queue_name', 'puzzles');
        Queue::fake();

        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->callAction('generatePuzzle')
            ->assertNotified(__('image-puzzle-filament::messages.queued'))
            ->assertSee(__('image-puzzle-filament::messages.status.queued'))
            ->assertSeeHtml('wire:poll.2s');

        self::assertSame(PuzzleRecord::STATUS_QUEUED, $puzzle->refresh()->imagePuzzleStatus());
        $job = null;
        Queue::assertPushedOn('puzzles', GeneratePuzzle::class, static function (GeneratePuzzle $pushed) use (&$job): bool {
            $job = $pushed;

            return true;
        });
        self::assertInstanceOf(GeneratePuzzle::class, $job);
        self::assertSame('puzzle.' . $puzzle->id, $job->puzzle);

        app()->call([$job, 'handle']);

        self::assertSame(PuzzleRecord::STATUS_READY, $puzzle->refresh()->imagePuzzleStatus());
        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->assertDontSeeHtml('wire:poll.2s')
            ->assertSeeHtml('<image-puzzle-player');
    }

    public function testAQueuedJobStoresInternalErrorsWithoutLeakingThem(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team);
        app(Config::class)->set('image-puzzle-filament.directory', '/dev/null/not-a-directory');
        app()->forgetInstance(\ImagePuzzle\Filament\PuzzleModels::class);
        app()->forgetInstance(PuzzleGeneration::class);

        app()->call([new GeneratePuzzle('puzzle.' . $puzzle->id), 'handle']);

        $puzzle->refresh();
        self::assertSame(PuzzleRecord::STATUS_FAILED, $puzzle->imagePuzzleStatus());
        self::assertSame(GenerationError::INTERNAL, $puzzle->imagePuzzleError());
    }

    public function testAJobForAnUnknownRecordDoesNothing(): void
    {
        app()->call([new GeneratePuzzle('puzzle.999'), 'handle']);
        app()->call([new GeneratePuzzle('unregistered.1'), 'handle']);
        $this->addToAssertionCount(1);
    }

    public function testDownloadsTheGeneratedPuzzleWithItsBuildConfiguration(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team);
        app(PuzzleGeneration::class)->generate($puzzle);

        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->callAction('downloadPuzzle')
            ->assertFileDownloaded('puzzle-' . $puzzle->id . '.zip');

        $zip = (string) DownloadPuzzleAction::archive($puzzle->refresh());
        $archive = new \ZipArchive();
        self::assertTrue($archive->open($zip));
        $names = [];
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $names[] = (string) $archive->getNameIndex($i);
        }
        self::assertContains('definition.json', $names);
        self::assertContains('build-config.json', $names);
        self::assertSame(self::config(), json_decode((string) $archive->getFromName('build-config.json'), true));
        $definition = json_decode((string) $archive->getFromName('definition.json'), true);
        self::assertIsArray($definition);
        $archive->close();
        unlink($zip);
    }

    public function testViewersCanDownloadButNotGenerate(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team);
        app(PuzzleGeneration::class)->generate($puzzle);
        $definition = $puzzle->refresh()->imagePuzzleDefinitionPath();
        Gate::before(static fn (mixed $user, string $ability): ?bool => $ability === 'update' ? false : null);

        Livewire::test(ViewPuzzle::class, ['record' => $puzzle->getRouteKey()])
            ->assertActionHidden('generatePuzzle')
            ->assertActionVisible('downloadPuzzle')
            ->assertSeeHtml('<image-puzzle-player')
            ->call('mountAction', 'generatePuzzle')
            ->assertActionNotMounted('generatePuzzle');

        self::assertSame($definition, $puzzle->refresh()->imagePuzzleDefinitionPath(), 'A hidden action cannot be mounted from the browser.');
    }

    public function testPagesRequireTheRecordPolicy(): void
    {
        [$team] = $this->signIn();
        $puzzle = $this->puzzleFor($team);
        Gate::before(static fn (mixed $user, string $ability): ?bool => in_array($ability, ['update', 'view'], true) ? false : null);

        Livewire::test(ViewPuzzle::class, ['record' => $puzzle->getRouteKey()])->assertForbidden();
        Livewire::test(EditPuzzle::class, ['record' => $puzzle->getRouteKey()])->assertForbidden();
    }
}
