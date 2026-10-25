<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire\Jobs;

use ImagePuzzle\Batch\BatchItem;
use ImagePuzzle\Batch\ItemResult;

/** Dispatched after BuildPuzzle verified an item's output directory. */
final class PuzzleBuilt
{
    public function __construct(
        public readonly BatchItem $item,
        public readonly ItemResult $result,
    ) {
    }
}
