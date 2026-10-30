<?php

declare(strict_types=1);

namespace ImagePuzzle\Batch;

use ImagePuzzle\Cache\AssetCache;
use ImagePuzzle\Cache\CachedBuilder;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Export\PcntlProcessControl;
use ImagePuzzle\Export\ProcessControl;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\PuzzleDirectory;
use ImagePuzzle\Support\ErrorCapture;
use ImagePuzzle\Support\Tree;

/**
 * Builds many puzzles with per-item status, progress events, cooperative
 * cancellation, fail-fast or continue-on-failure, optional bounded
 * concurrency (forked workers) and a resumable checkpoint.
 *
 * Every item writes into its own new or empty output directory; an item
 * that fails or is cancelled has its output removed, so a directory either
 * holds a verified puzzle or nothing. A batch is "complete" only when every
 * item is.
 *
 * Queue integration: runItem() builds one item and is safe to call from a
 * queued job (Laravel or any other); the host keeps the per-item status,
 * as the checkpoint does for run().
 */
final class BatchRunner
{
    private readonly ProcessControl $process;

    public function __construct(
        private readonly DefinitionBuilder $builder = new DefinitionBuilder(),
        private readonly ?AssetCache $cache = null,
        ?ProcessControl $process = null,
    ) {
        $this->process = $process ?? new PcntlProcessControl();
    }

    /**
     * @param list<BatchItem> $items
     * @param (\Closure(BatchEvent): void)|null $listener
     */
    public function run(array $items, BatchOptions $options = new BatchOptions(), ?\Closure $listener = null, ?CancellationToken $token = null): BatchResult
    {
        $ids = array_map(static fn (BatchItem $item): string => $item->id, $items);
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('Batch item IDs must be unique.');
        }
        $outputs = array_map(static fn (BatchItem $item): string => $item->output, $items);
        if (count(array_unique($outputs)) !== count($outputs)) {
            throw new InvalidArgumentException('Batch items must write to different output directories.');
        }
        $token ??= new CancellationToken();
        $checkpoint = $options->checkpoint === null ? null : new Checkpoint($options->checkpoint);
        $total = count($items);
        $emit = static function (BatchEvent $event) use ($listener): void {
            if ($listener !== null) {
                $listener($event);
            }
        };
        $emit(new BatchEvent('batch-start', 0, $total));

        /** @var array<int, ItemResult> $results */
        $results = [];
        $queue = [];
        foreach ($items as $index => $item) {
            $resumed = $checkpoint === null ? null : $this->resume($item, $checkpoint);
            if ($resumed !== null) {
                $results[$index] = $resumed;
                $emit(new BatchEvent('item-end', count($results), $total, $item->id, status: ItemResult::RESUMED));
            } else {
                $queue[] = $index;
            }
        }

        $stopped = null;
        $finish = function (int $index, ItemResult $result) use (&$results, &$stopped, $items, $checkpoint, $options, $emit, $total): void {
            $item = $items[$index];
            $results[$index] = $result;
            if ($checkpoint !== null) {
                if ($result->status === ItemResult::DONE) {
                    $checkpoint->markDone($item, $result);
                } else {
                    $checkpoint->forget($item);
                }
            }
            $emit(new BatchEvent('item-end', count($results), $total, $item->id, status: $result->status, error: $result->error));
            if ($result->status === ItemResult::FAILED && $options->failFast) {
                $stopped ??= BatchResult::FAILED;
            }
        };

        if ($options->concurrency > 1 && $this->process->isAvailable() && count($queue) > 1) {
            $this->runForked($items, $queue, $options->concurrency, $token, $checkpoint, $finish, $stopped, $emit, $total, $results);
        } else {
            foreach ($queue as $index) {
                if ($stopped !== null) {
                    break;
                }
                if ($token->isCancelled()) {
                    $stopped = BatchResult::CANCELED;
                    break;
                }
                $item = $items[$index];
                $emit(new BatchEvent('item-start', count($results), $total, $item->id));
                $checkpoint?->markStarted($item);
                $finish($index, $this->runItem($item, $token, static function (string $stage) use ($emit, $item, $total, &$results): void {
                    $emit(new BatchEvent('item-stage', count($results), $total, $item->id, $stage));
                }));
            }
        }
        if ($stopped === null && $token->isCancelled() && count($results) < $total) {
            $stopped = BatchResult::CANCELED;
        }

        $list = [];
        foreach ($items as $index => $item) {
            $list[] = $results[$index] ?? new ItemResult($item->id, ItemResult::PENDING);
        }
        $failed = array_filter($list, static fn (ItemResult $r): bool => $r->status === ItemResult::FAILED) !== [];
        $cancelled = array_filter($list, static fn (ItemResult $r): bool => $r->status === ItemResult::CANCELED) !== [];
        $status = $stopped ?? ($cancelled ? BatchResult::CANCELED : ($failed ? BatchResult::PARTIAL : BatchResult::COMPLETE));
        $emit(new BatchEvent('batch-end', count($results), $total, status: $status));

        return new BatchResult($status, $list);
    }

    /**
     * Builds one item into its output directory, which must be new or empty.
     * Never throws for item problems: they come back as a failed result with
     * the output removed.
     *
     * @param (\Closure(string): void)|null $stage receives "prepare", "build" and "verify"
     */
    public function runItem(BatchItem $item, ?CancellationToken $token = null, ?\Closure $stage = null): ItemResult
    {
        $token ??= new CancellationToken();
        $created = !file_exists($item->output);
        $owned = false;
        try {
            $stage?->__invoke('prepare');
            if ($token->isCancelled()) {
                return new ItemResult($item->id, ItemResult::CANCELED);
            }
            if (!$created && (!is_dir($item->output) || Tree::files($item->output) !== [] || self::hasEntries($item->output))) {
                return new ItemResult($item->id, ItemResult::FAILED, error: "Output directory {$item->output} must be new or empty.");
            }
            $owned = true;
            $stage?->__invoke('build');
            $fromCache = false;
            if ($this->cache !== null) {
                $cached = (new CachedBuilder($this->cache, $this->builder))->build($item->source, $item->config, $item->assets);
                self::copyTree($cached->puzzle->path, $item->output);
                $fromCache = $cached->hit;
            } else {
                $this->builder->buildConfigured($item->source, $item->output, $item->config, $item->assets);
            }
            if ($token->isCancelled()) {
                self::clear($item->output, $created);

                return new ItemResult($item->id, ItemResult::CANCELED);
            }
            $stage?->__invoke('verify');
            [$definitionId, $files] = self::verify($item->output);

            return new ItemResult($item->id, ItemResult::DONE, $definitionId, $files, fromCache: $fromCache);
        } catch (\Throwable $e) {
            if ($owned) {
                self::clear($item->output, $created);
            }

            return new ItemResult($item->id, ItemResult::FAILED, error: self::describe($e));
        }
    }

    /**
     * Definition ID and file hashes of a built puzzle directory; throws when
     * the definition or a referenced file is missing or invalid.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function verify(string $dir): array
    {
        $puzzle = PuzzleDirectory::open($dir);
        foreach ($puzzle->referencedFiles() as $file) {
            $puzzle->file($file);
        }
        $files = [];
        foreach (Tree::files($dir) as $file) {
            $files[$file] = (string) hash_file('sha256', $dir . '/' . $file);
        }

        return [$puzzle->definition->id, $files];
    }

    private function resume(BatchItem $item, Checkpoint $checkpoint): ?ItemResult
    {
        $done = $checkpoint->done($item);
        if ($done !== null) {
            try {
                [$definitionId, $files] = self::verify($item->output);
                if ($definitionId === $done['definitionId'] && $files === $done['files']) {
                    return new ItemResult($item->id, ItemResult::RESUMED, $definitionId, $files);
                }
            } catch (\Throwable) {
                // Rebuilt below.
            }
        }
        if ($checkpoint->owns($item) && file_exists($item->output)) {
            // Written by this batch but unfinished, changed or corrupt: start the item again.
            self::clear($item->output, false);
        }

        return null;
    }

    /**
     * @param list<BatchItem> $items
     * @param list<int> $queue
     * @param \Closure(int, ItemResult): void $finish
     * @param \Closure(BatchEvent): void $emit
     * @param array<int, ItemResult> $results
     */
    private function runForked(array $items, array $queue, int $concurrency, CancellationToken $token, ?Checkpoint $checkpoint, \Closure $finish, ?string &$stopped, \Closure $emit, int $total, array &$results): void
    {
        $dir = sys_get_temp_dir() . '/image-puzzle-batch-' . bin2hex(random_bytes(6));
        if (!ErrorCapture::run(static fn (): bool => mkdir($dir, 0o700))) {
            throw new \RuntimeException('Cannot create a directory for batch worker results.');
        }
        /** @var array<int, int> $running pid => item index */
        $running = [];
        /** @var array<int, bool> $created item index => output did not exist when its worker started */
        $created = [];
        try {
            while ($queue !== [] || $running !== []) {
                if ($stopped === null && $token->isCancelled()) {
                    $stopped = BatchResult::CANCELED;
                }
                while ($stopped === null && $queue !== [] && count($running) < $concurrency) {
                    $index = (int) array_shift($queue);
                    $item = $items[$index];
                    $emit(new BatchEvent('item-start', count($results), $total, $item->id));
                    $checkpoint?->markStarted($item);
                    $created[$index] = !file_exists($item->output);
                    $pid = $this->process->fork();
                    if ($pid === -1) {
                        $finish($index, new ItemResult($item->id, ItemResult::FAILED, error: 'Could not start a worker process.'));
                        continue;
                    }
                    if ($pid === 0) {
                        $result = $this->runItem($item, $token);
                        @file_put_contents("{$dir}/{$index}.json", json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                        $this->process->terminateChild(0);
                    }
                    $running[$pid] = $index;
                }
                if ($stopped !== null) {
                    $queue = [];
                    foreach (array_keys($running) as $pid) {
                        $this->process->signal($pid, defined('SIGTERM') ? SIGTERM : 15);
                    }
                }
                if ($running === []) {
                    break;
                }
                $waited = $this->process->wait(-1);
                if ($waited['pid'] === -1 && !$waited['interrupted']) {
                    // waitpid failed: the running workers can no longer be
                    // tracked. Stop and reap them, and fail their items,
                    // rather than waiting again forever.
                    foreach ($running as $pid => $lost) {
                        $this->process->signal($pid, defined('SIGKILL') ? SIGKILL : 9);
                        do {
                            $reaped = $this->process->wait($pid);
                        } while ($reaped['interrupted']);
                        if (file_exists($items[$lost]->output)) {
                            self::clear($items[$lost]->output, $created[$lost] ?? false);
                        }
                        $finish($lost, new ItemResult($items[$lost]->id, ItemResult::FAILED, error: 'Lost track of the worker process (waitpid failed).'));
                    }
                    $running = [];
                    continue;
                }
                if ($waited['interrupted'] || !isset($running[$waited['pid']])) {
                    continue;
                }
                $index = $running[$waited['pid']];
                unset($running[$waited['pid']]);
                $item = $items[$index];
                $file = "{$dir}/{$index}.json";
                $data = is_file($file) ? json_decode((string) file_get_contents($file), true, 16) : null;
                if (is_array($data) && $stopped === null) {
                    $result = ItemResult::fromArray($data);
                } elseif ($stopped !== null) {
                    $result = new ItemResult($item->id, ItemResult::CANCELED);
                } else {
                    $signal = $this->process->termSignal($waited['status']);
                    $result = new ItemResult($item->id, ItemResult::FAILED, error: $signal === null
                        ? 'Worker exited with code ' . $this->process->exitCode($waited['status']) . ' without a result.'
                        : "Worker was terminated by signal {$signal}.");
                }
                // Like runItem(): a failed item leaves no output directory it created.
                if (!$result->succeeded() && file_exists($item->output)) {
                    self::clear($item->output, $created[$index] ?? false);
                }
                $finish($index, $result);
            }
        } finally {
            Tree::remove($dir);
        }
    }

    private static function hasEntries(string $dir): bool
    {
        $entries = scandir($dir);

        return $entries === false || array_diff($entries, ['.', '..']) !== [];
    }

    /** Empties $dir, removing it too when this run created it. */
    private static function clear(string $dir, bool $remove): void
    {
        if ($remove) {
            Tree::remove($dir);

            return;
        }
        $entries = scandir($dir);
        foreach ($entries === false ? [] : array_diff($entries, ['.', '..']) as $entry) {
            Tree::remove($dir . '/' . $entry);
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !ErrorCapture::run(static fn (): bool => mkdir($to, 0o755, true))) {
            throw new \RuntimeException("Cannot create {$to}.");
        }
        foreach (Tree::files($from) as $file) {
            $target = $to . '/' . $file;
            $parent = dirname($target);
            if (!is_dir($parent) && !ErrorCapture::run(static fn (): bool => mkdir($parent, 0o755, true))) {
                throw new \RuntimeException("Cannot create {$parent}.");
            }
            if (!ErrorCapture::run(static fn (): bool => copy($from . '/' . $file, $target))) {
                throw new \RuntimeException("Cannot copy {$file} from the cache.");
            }
        }
    }

    private static function describe(\Throwable $e): string
    {
        $class = (new \ReflectionClass($e))->getShortName();

        return "{$class}: {$e->getMessage()}";
    }
}
