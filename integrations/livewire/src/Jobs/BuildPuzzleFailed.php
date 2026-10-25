<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire\Jobs;

/** A queued puzzle build failed; its output directory was left empty. */
final class BuildPuzzleFailed extends \RuntimeException
{
}
