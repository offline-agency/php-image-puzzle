<?php

declare(strict_types=1);

namespace ImagePuzzle\Batch;

use ImagePuzzle\Cache\CacheKey;
use ImagePuzzle\Exception\StorageException;
use ImagePuzzle\Game\CanonicalJson;
use ImagePuzzle\Support\ErrorCapture;

/**
 * Resumable record of a batch, rewritten atomically after every change:
 *
 *     {"format": "image-puzzle/batch-checkpoint", "version": 1,
 *      "engine": "<digest of library versions and image backend>",
 *      "items": {"<id>": {"identity": "<digest of source, output, config, options>",
 *                         "output": "...", "status": "started" | "done",
 *                         "definitionId": "...", "files": {"path": "sha256", ...}}}}
 *
 * A record counts only when its engine and the item's identity still match
 * and the files on disk still hash to the recorded values; the runner checks
 * that before skipping an item. "started" marks an output this batch began
 * writing, so a rerun may clear it; outputs the checkpoint does not record
 * are never deleted.
 */
final class Checkpoint
{
    public const FORMAT = 'image-puzzle/batch-checkpoint';
    public const VERSION = 1;
    public const STARTED = 'started';
    public const DONE = 'done';

    /** @var array<string, array{identity: string, output: string, status: string, definitionId?: string, files?: array<string, string>}> */
    private array $items = [];
    private readonly string $engine;

    public function __construct(private readonly string $path)
    {
        $this->engine = CanonicalJson::digest(CacheKey::engine());
        if (!is_file($path)) {
            return;
        }
        $doc = json_decode((string) file_get_contents($path), true, 16);
        if (!is_array($doc) || ($doc['format'] ?? null) !== self::FORMAT || ($doc['version'] ?? null) !== self::VERSION || !is_array($doc['items'] ?? null)) {
            throw new StorageException("{$path} is not a version " . self::VERSION . ' batch checkpoint.');
        }
        $sameEngine = ($doc['engine'] ?? null) === $this->engine;
        foreach ($doc['items'] as $id => $record) {
            if (!is_array($record) || !is_string($record['identity'] ?? null) || !is_string($record['output'] ?? null) || !in_array($record['status'] ?? null, [self::STARTED, self::DONE], true)) {
                continue;
            }
            $entry = ['identity' => $record['identity'], 'output' => $record['output'], 'status' => $sameEngine ? $record['status'] : self::STARTED];
            if ($entry['status'] === self::DONE && is_string($record['definitionId'] ?? null) && is_array($record['files'] ?? null)) {
                $entry['definitionId'] = $record['definitionId'];
                $files = [];
                foreach ($record['files'] as $file => $hash) {
                    if (is_string($hash)) {
                        $files[(string) $file] = $hash;
                    }
                }
                $entry['files'] = $files;
            } else {
                $entry['status'] = self::STARTED;
            }
            $this->items[(string) $id] = $entry;
        }
    }

    public static function identity(BatchItem $item): string
    {
        // Bound to the source content too: a replaced image at the same path is a new item.
        $source = is_file($item->source) ? hash_file('sha256', $item->source) : false;

        return CanonicalJson::digest([...$item->identity(), 'sourceSha256' => $source === false ? null : $source]);
    }

    /**
     * The verified-later result recorded for this exact item, if any.
     *
     * @return array{definitionId: string, files: array<string, string>}|null
     */
    public function done(BatchItem $item): ?array
    {
        $record = $this->items[$item->id] ?? null;
        if ($record === null || $record['status'] !== self::DONE || $record['identity'] !== self::identity($item) || $record['output'] !== $item->output) {
            return null;
        }

        return ['definitionId' => $record['definitionId'] ?? '', 'files' => $record['files'] ?? []];
    }

    /** Whether this batch wrote (or began writing) the item's output directory. */
    public function owns(BatchItem $item): bool
    {
        return isset($this->items[$item->id]) && $this->items[$item->id]['output'] === $item->output;
    }

    public function markStarted(BatchItem $item): void
    {
        $this->items[$item->id] = ['identity' => self::identity($item), 'output' => $item->output, 'status' => self::STARTED];
        $this->save();
    }

    public function markDone(BatchItem $item, ItemResult $result): void
    {
        $this->items[$item->id] = [
            'identity' => self::identity($item),
            'output' => $item->output,
            'status' => self::DONE,
            'definitionId' => (string) $result->definitionId,
            'files' => $result->files,
        ];
        $this->save();
    }

    public function forget(BatchItem $item): void
    {
        unset($this->items[$item->id]);
        $this->save();
    }

    private function save(): void
    {
        ksort($this->items);
        $json = CanonicalJson::encode(['format' => self::FORMAT, 'version' => self::VERSION, 'engine' => $this->engine, 'items' => $this->items === [] ? new \stdClass() : $this->items]) . "\n";
        $temp = $this->path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temp, $json) !== strlen($json) || !ErrorCapture::run(fn (): bool => rename($temp, $this->path))) {
            @unlink($temp);
            throw new StorageException("Cannot write the batch checkpoint {$this->path}.");
        }
    }
}
