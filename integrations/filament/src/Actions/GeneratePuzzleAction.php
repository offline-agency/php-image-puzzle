<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;
use ImagePuzzle\Filament\GenerationError;
use ImagePuzzle\Filament\Jobs\GeneratePuzzle;
use ImagePuzzle\Filament\PuzzleGeneration;
use ImagePuzzle\Filament\PuzzleModels;

/**
 * Generates the record's puzzle from its saved source image and
 * configuration, during the request or in a queued job. Requires the
 * "update" policy ability on the record; unsaved form changes are not used.
 */
class GeneratePuzzleAction extends Action
{
    protected bool | \Closure | null $queued = null;

    public static function getDefaultName(): ?string
    {
        return 'generatePuzzle';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('image-puzzle-filament::messages.generate'));
        $this->icon(Heroicon::OutlinedPuzzlePiece);
        $this->authorize('update');
        $this->visible(static fn (mixed $record): bool => $record instanceof PuzzleRecord && $record instanceof Model && $record->exists);
        $this->action(function (mixed $record): void {
            if (!$record instanceof PuzzleRecord || !$record instanceof Model) {
                return;
            }
            $this->isQueued() ? $this->dispatchJob($record) : $this->generateNow($record);
        });
    }

    /** Run generation in a queued job; defaults to image-puzzle-filament.queue. */
    public function queued(bool | \Closure | null $condition = true): static
    {
        $this->queued = $condition;

        return $this;
    }

    public function isQueued(): bool
    {
        $queued = $this->evaluate($this->queued);

        return is_bool($queued) ? $queued : (bool) app(Config::class)->get('image-puzzle-filament.queue', false);
    }

    private function generateNow(Model & PuzzleRecord $record): void
    {
        $record->markImagePuzzleStatus(PuzzleRecord::STATUS_RUNNING);
        try {
            app(PuzzleGeneration::class)->generate($record);
        } catch (\Throwable $e) {
            $message = GenerationError::forAuthor($e);
            $record->markImagePuzzleStatus(PuzzleRecord::STATUS_FAILED, $message ?? GenerationError::INTERNAL);
            if ($message === null) {
                app(ExceptionHandler::class)->report($e);
            }
            Notification::make()
                ->danger()
                ->title(__('image-puzzle-filament::messages.generation_failed'))
                ->body($message ?? GenerationError::INTERNAL)
                ->send();
            $this->failure();

            return;
        }
        Notification::make()->success()->title(__('image-puzzle-filament::messages.generated'))->send();
        $this->success();
    }

    private function dispatchJob(Model & PuzzleRecord $record): void
    {
        $config = app(Config::class);
        $record->markImagePuzzleStatus(PuzzleRecord::STATUS_QUEUED);
        $job = new GeneratePuzzle(app(PuzzleModels::class)->keyFor($record));
        $connection = $config->get('image-puzzle-filament.queue_connection');
        $queue = $config->get('image-puzzle-filament.queue_name');
        if (is_string($connection) && $connection !== '') {
            $job->onConnection($connection);
        }
        if (is_string($queue) && $queue !== '') {
            $job->onQueue($queue);
        }
        dispatch($job);
        Notification::make()->info()->title(__('image-puzzle-filament::messages.queued'))->send();
    }
}
