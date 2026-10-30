<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Event;
use ImagePuzzle\Batch\BatchItem;
use ImagePuzzle\Batch\BatchRunner;
use ImagePuzzle\Batch\CancellationToken;
use ImagePuzzle\Batch\ItemResult;
use ImagePuzzle\Cache\AssetCache;
use ImagePuzzle\Game\DefinitionBuilder;

/**
 * Builds one batch item on a Laravel queue, optionally inside Bus::batch().
 * The core library never needs a queue: this job is a thin wrapper around
 * BatchRunner::runItem(), which leaves the output either verified or empty.
 *
 * - A failed item fails the job (BuildPuzzleFailed), so the queue records
 *   it and may retry it (the output was cleared), and a Bus batch sees it.
 * - A cancelled Bus batch, or an existing cancel file, skips the item.
 * - A built item dispatches PuzzleBuilt with the definition ID and hashes.
 */
final class BuildPuzzle implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(
        public readonly BatchItem $item,
        public readonly ?string $cancelFile = null,
        public readonly ?string $cacheDir = null,
    ) {
    }

    public function handle(DefinitionBuilder $builder): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }
        $cache = $this->cacheDir === null ? null : new AssetCache($this->cacheDir);
        $result = (new BatchRunner($builder, $cache))->runItem($this->item, new CancellationToken($this->cancelFile));
        if ($result->status === ItemResult::FAILED) {
            throw new BuildPuzzleFailed("Puzzle {$this->item->id} failed: {$result->error}");
        }
        if ($result->status === ItemResult::DONE) {
            Event::dispatch(new PuzzleBuilt($this->item, $result));
        }
    }
}
