<?php

declare(strict_types=1);

namespace ImagePuzzle\Batch;

use ImagePuzzle\Exception\InvalidArgumentException;

final readonly class BatchOptions
{
    public const MAX_CONCURRENCY = 32;

    public function __construct(
        /**
         * Stop at the first failure: no new item starts, running items are
         * stopped and cleaned up, and the batch ends "failed". Otherwise
         * every item runs and failures are reported per item ("partial").
         */
        public bool $failFast = false,
        /** Items built at once in forked workers (needs ext-pcntl; 1 runs in-process). */
        public int $concurrency = 1,
        /** JSON file recording finished items, so a rerun resumes after them. */
        public ?string $checkpoint = null,
    ) {
        if ($concurrency < 1 || $concurrency > self::MAX_CONCURRENCY) {
            throw new InvalidArgumentException('Batch concurrency must be between 1 and ' . self::MAX_CONCURRENCY . '.');
        }
    }
}
