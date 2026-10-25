<?php

declare(strict_types=1);

namespace ImagePuzzle\Batch;

/**
 * Progress of a batch. Types:
 *
 * - batch-start, batch-end (with the final status in $status);
 * - item-start; item-stage with $stage = prepare, build, verify (in-process
 *   runs only: forked workers report item boundaries);
 * - item-end with the item's status (done, resumed, failed, canceled).
 *
 * $completed counts finished items (any status) out of $total.
 */
final readonly class BatchEvent
{
    public function __construct(
        public string $type,
        public int $completed,
        public int $total,
        public ?string $item = null,
        public ?string $stage = null,
        public ?string $status = null,
        public ?string $error = null,
    ) {
    }
}
