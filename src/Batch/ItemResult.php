<?php

declare(strict_types=1);

namespace ImagePuzzle\Batch;

/**
 * Outcome of one item:
 *
 * - done: built (or copied from the cache) and verified in this run;
 * - resumed: recorded as done by the checkpoint and still verified on disk;
 * - failed: the build or the verification failed; the output was removed;
 * - canceled: stopped by cancellation or fail-fast; the output was removed;
 * - pending: never started (the batch stopped first).
 */
final readonly class ItemResult
{
    public const DONE = 'done';
    public const RESUMED = 'resumed';
    public const FAILED = 'failed';
    public const CANCELED = 'canceled';
    public const PENDING = 'pending';

    /**
     * @param array<string, string> $files output files and their SHA-256, for done and resumed items
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $definitionId = null,
        public array $files = [],
        public ?string $error = null,
        public bool $fromCache = false,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->status === self::DONE || $this->status === self::RESUMED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'status' => $this->status,
            'definitionId' => $this->definitionId,
            'files' => $this->files === [] ? null : $this->files,
            'error' => $this->error,
            'fromCache' => $this->fromCache ? true : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $files = [];
        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $path => $hash) {
            if (is_string($hash)) {
                $files[(string) $path] = $hash;
            }
        }

        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            is_string($data['status'] ?? null) ? $data['status'] : self::FAILED,
            is_string($data['definitionId'] ?? null) ? $data['definitionId'] : null,
            $files,
            is_string($data['error'] ?? null) ? $data['error'] : null,
            ($data['fromCache'] ?? false) === true,
        );
    }
}
