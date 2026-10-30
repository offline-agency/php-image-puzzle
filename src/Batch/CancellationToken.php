<?php

declare(strict_types=1);

namespace ImagePuzzle\Batch;

/**
 * Cooperative cancellation. Work checks the token between stages and items;
 * nothing is interrupted mid-write. A token bound to a file is also
 * cancelled when that file exists, which reaches forked workers and other
 * processes (a queue worker, a CLI run) without shared memory.
 */
final class CancellationToken
{
    private bool $cancelled = false;

    public function __construct(private readonly ?string $file = null)
    {
    }

    public function cancel(): void
    {
        $this->cancelled = true;
        if ($this->file !== null) {
            @touch($this->file);
        }
    }

    /** @phpstan-impure */
    public function isCancelled(): bool
    {
        if (!$this->cancelled && $this->file !== null) {
            clearstatcache(true, $this->file);
            $this->cancelled = file_exists($this->file);
        }

        return $this->cancelled;
    }
}
