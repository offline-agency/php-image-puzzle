<?php

declare(strict_types=1);

namespace ImagePuzzle\Batch;

/**
 * Status:
 *
 * - complete: every item is done or resumed;
 * - partial: the batch ran to the end but some items failed;
 * - failed: fail-fast stopped the batch at a failure;
 * - canceled: the token was cancelled before every item finished.
 *
 * Only "complete" means the whole batch is usable.
 */
final readonly class BatchResult
{
    public const COMPLETE = 'complete';
    public const PARTIAL = 'partial';
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';

    /**
     * @param list<ItemResult> $items in input order
     */
    public function __construct(
        public string $status,
        public array $items,
    ) {
    }

    /**
     * @return array<string, int> count per item status
     */
    public function counts(): array
    {
        $counts = array_fill_keys([ItemResult::DONE, ItemResult::RESUMED, ItemResult::FAILED, ItemResult::CANCELED, ItemResult::PENDING], 0);
        foreach ($this->items as $item) {
            $counts[$item->status] = ($counts[$item->status] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'counts' => $this->counts(), 'items' => array_map(static fn (ItemResult $item): array => $item->toArray(), $this->items)];
    }
}
