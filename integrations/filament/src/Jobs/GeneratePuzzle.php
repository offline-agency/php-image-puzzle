<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use ImagePuzzle\Filament\Contracts\PuzzleRecord;
use ImagePuzzle\Filament\GenerationError;
use ImagePuzzle\Filament\PuzzleGeneration;
use ImagePuzzle\Filament\PuzzleModels;

/**
 * Queued generation. Progress is reported through the record status
 * (queued -> running -> ready | failed); errors the author can fix are
 * stored as the record error, anything else is reported to the host.
 */
final class GeneratePuzzle implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Generation is deterministic: retrying a failed build cannot help. */
    public int $tries = 1;

    public function __construct(public readonly string $puzzle)
    {
    }

    public function handle(PuzzleModels $models, PuzzleGeneration $generation, ExceptionHandler $handler): void
    {
        $record = $models->find($this->puzzle);
        if ($record === null) {
            return;
        }
        $record->markImagePuzzleStatus(PuzzleRecord::STATUS_RUNNING);
        try {
            $generation->generate($record);
        } catch (\Throwable $e) {
            $message = GenerationError::forAuthor($e);
            $record->markImagePuzzleStatus(PuzzleRecord::STATUS_FAILED, $message ?? GenerationError::INTERNAL);
            if ($message === null) {
                $handler->report($e);
            }
        }
    }
}
