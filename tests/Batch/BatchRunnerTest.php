<?php

declare(strict_types=1);

use ImagePuzzle\Batch\BatchEvent;
use ImagePuzzle\Batch\BatchItem;
use ImagePuzzle\Batch\BatchOptions;
use ImagePuzzle\Batch\BatchResult;
use ImagePuzzle\Batch\BatchRunner;
use ImagePuzzle\Batch\CancellationToken;
use ImagePuzzle\Batch\ItemResult;
use ImagePuzzle\Cache\AssetCache;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Export\PcntlProcessControl;
use ImagePuzzle\Export\ProcessControl;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Support\Tree;
use ImagePuzzle\Tests\Support\Fixtures;

final class BatchWorkspaces
{
    /** @var list<string> */
    public static array $dirs = [];
}

function batchDir(): string
{
    $dir = Fixtures::tempDir('ip-batch-');
    BatchWorkspaces::$dirs[] = $dir;
    imagepng(Fixtures::coordinateImage(96, 72, false), $dir . '/photo.png');

    return $dir;
}

function batchItem(string $dir, string $id, int $seed = 1, ?string $source = null): BatchItem
{
    return new BatchItem($id, $source ?? $dir . '/photo.png', $dir . '/out/' . $id, new BuildConfiguration(GameKind::Swap, 'easy', [], $seed));
}

/**
 * @param list<BatchItem> $items
 * @param list<BatchEvent> $events
 */
function runBatch(array $items, BatchOptions $options = new BatchOptions(), array &$events = [], ?CancellationToken $token = null, ?Closure $react = null, ?AssetCache $cache = null): BatchResult
{
    return (new BatchRunner(new DefinitionBuilder(), $cache))->run($items, $options, static function (BatchEvent $event) use (&$events, $react): void {
        $events[] = $event;
        if ($react !== null) {
            $react($event);
        }
    }, $token);
}

/**
 * @return list<string>
 */
function statuses(BatchResult $result): array
{
    return array_map(static fn (ItemResult $item): string => $item->status, $result->items);
}

afterEach(function (): void {
    foreach (BatchWorkspaces::$dirs as $dir) {
        Fixtures::removeDir($dir);
    }
    BatchWorkspaces::$dirs = [];
});

describe('running', function (): void {
    it('builds every item and reports progress', function (): void {
        $dir = batchDir();
        $events = [];
        $result = runBatch([batchItem($dir, 'a'), batchItem($dir, 'b', 2)], events: $events);
        expect($result->status)->toBe(BatchResult::COMPLETE)
            ->and(statuses($result))->toBe(['done', 'done'])
            ->and($result->counts()['done'])->toBe(2);
        foreach ($result->items as $item) {
            expect(BatchRunner::verify($dir . '/out/' . $item->id)[0])->toBe($item->definitionId)
                ->and($item->files)->toHaveKey('definition.json');
        }
        $trace = array_map(static fn (BatchEvent $e): string => $e->type . ($e->stage === null ? '' : ':' . $e->stage) . ' ' . $e->completed . '/' . $e->total, $events);
        expect($trace)->toBe([
            'batch-start 0/2',
            'item-start 0/2', 'item-stage:prepare 0/2', 'item-stage:build 0/2', 'item-stage:verify 0/2', 'item-end 1/2',
            'item-start 1/2', 'item-stage:prepare 1/2', 'item-stage:build 1/2', 'item-stage:verify 1/2', 'item-end 2/2',
            'batch-end 2/2',
        ]);
    });

    it('isolates a failing item and reports the batch as partial', function (): void {
        $dir = batchDir();
        $result = runBatch([batchItem($dir, 'a'), batchItem($dir, 'broken', source: $dir . '/missing.png'), batchItem($dir, 'c', 3)]);
        expect($result->status)->toBe(BatchResult::PARTIAL)
            ->and(statuses($result))->toBe(['done', 'failed', 'done'])
            ->and($result->items[1]->error)->toContain('missing.png')
            ->and(file_exists($dir . '/out/broken'))->toBeFalse()
            ->and($result->toArray()['status'])->toBe('partial');
    });

    it('stops at the first failure in fail-fast mode and leaves the rest pending', function (): void {
        $dir = batchDir();
        $result = runBatch([batchItem($dir, 'broken', source: $dir . '/missing.png'), batchItem($dir, 'b')], new BatchOptions(failFast: true));
        expect($result->status)->toBe(BatchResult::FAILED)
            ->and(statuses($result))->toBe(['failed', 'pending'])
            ->and(file_exists($dir . '/out/b'))->toBeFalse();
    });

    it('never overwrites an existing non-empty output', function (): void {
        $dir = batchDir();
        mkdir($dir . '/out/a', 0o755, true);
        file_put_contents($dir . '/out/a/keep.txt', 'mine');
        $result = runBatch([batchItem($dir, 'a')]);
        expect(statuses($result))->toBe(['failed'])
            ->and($result->items[0]->error)->toContain('must be new or empty')
            ->and(file_get_contents($dir . '/out/a/keep.txt'))->toBe('mine');
    });

    it('rejects duplicate IDs, shared outputs and invalid options', function (): void {
        $dir = batchDir();
        expect(fn () => runBatch([batchItem($dir, 'a'), batchItem($dir, 'a', 2)]))->toThrow(InvalidArgumentException::class, 'unique')
            ->and(fn () => runBatch([batchItem($dir, 'a'), new BatchItem('b', $dir . '/photo.png', $dir . '/out/a', new BuildConfiguration(GameKind::Swap, 'easy'))]))->toThrow(InvalidArgumentException::class, 'different output')
            ->and(fn () => batchItem($dir, '../a'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new BatchOptions(concurrency: 0))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new BatchOptions(concurrency: 33))->toThrow(InvalidArgumentException::class);
    });

    it('serves repeated builds from the cache', function (): void {
        $dir = batchDir();
        $cache = new AssetCache($dir . '/cache');
        $first = runBatch([batchItem($dir, 'a')], cache: $cache);
        $second = runBatch([new BatchItem('b', $dir . '/photo.png', $dir . '/out/b', new BuildConfiguration(GameKind::Swap, 'easy', [], 1))], cache: $cache);
        expect($first->items[0]->fromCache)->toBeFalse()
            ->and($second->items[0]->fromCache)->toBeTrue()
            ->and($second->items[0]->files)->toBe($first->items[0]->files);
    });
});

describe('cancellation', function (): void {
    it('stops before the next item and leaves it pending', function (): void {
        $dir = batchDir();
        $token = new CancellationToken();
        $events = [];
        $result = runBatch([batchItem($dir, 'a'), batchItem($dir, 'b', 2)], events: $events, token: $token, react: static function (BatchEvent $e) use ($token): void {
            if ($e->type === 'item-end') {
                $token->cancel();
            }
        });
        expect($result->status)->toBe(BatchResult::CANCELED)
            ->and(statuses($result))->toBe(['done', 'pending'])
            ->and(end($events))->toEqual(new BatchEvent('batch-end', 1, 2, status: 'canceled'));
    });

    it('discards the output of an item cancelled while it builds', function (): void {
        $dir = batchDir();
        $token = new CancellationToken();
        $result = runBatch([batchItem($dir, 'a')], token: $token, react: static function (BatchEvent $e) use ($token): void {
            if ($e->stage === 'build') {
                $token->cancel();
            }
        });
        expect($result->status)->toBe(BatchResult::CANCELED)
            ->and(statuses($result))->toBe(['canceled'])
            ->and(file_exists($dir . '/out/a'))->toBeFalse();
    });

    it('can be signalled from another process through a file', function (): void {
        $dir = batchDir();
        $flag = $dir . '/cancel.flag';
        $token = new CancellationToken($flag);
        expect($token->isCancelled())->toBeFalse();
        (new CancellationToken($flag))->cancel();
        expect($token->isCancelled())->toBeTrue()
            ->and(runBatch([batchItem($dir, 'a')], token: $token)->status)->toBe(BatchResult::CANCELED);
    });
});

describe('checkpoints', function (): void {
    it('resume verified items and rebuild the others', function (): void {
        $dir = batchDir();
        $options = new BatchOptions(checkpoint: $dir . '/batch.checkpoint.json');
        $items = [batchItem($dir, 'a'), batchItem($dir, 'broken', source: $dir . '/missing.png')];
        expect(runBatch($items, $options)->status)->toBe(BatchResult::PARTIAL);

        copy($dir . '/photo.png', $dir . '/missing.png');
        $events = [];
        $result = runBatch($items, $options, $events);
        expect($result->status)->toBe(BatchResult::COMPLETE)
            ->and(statuses($result))->toBe(['resumed', 'done'])
            ->and($events[1])->toEqual(new BatchEvent('item-end', 1, 2, 'a', status: 'resumed'));
    });

    it('rebuild an item whose output, configuration or source changed', function (Closure $change): void {
        $dir = batchDir();
        $options = new BatchOptions(checkpoint: $dir . '/batch.checkpoint.json');
        $first = runBatch([batchItem($dir, 'a')], $options);
        $item = $change($dir);
        if (!$item instanceof BatchItem) {
            throw new LogicException('A change must return the item to run.');
        }
        $result = runBatch([$item], $options);
        expect(statuses($result))->toBe(['done']);
        $files = BatchRunner::verify($dir . '/out/a')[1];
        expect($files)->toBe($result->items[0]->files)
            ->and(Tree::files($dir . '/out/a'))->toBe(array_keys($files));
        if ($item->config->seed === 1 && hash_file('sha256', $dir . '/photo.png') === $first->items[0]->files['source.png']) {
            expect($files)->toBe($first->items[0]->files);
        }
    })->with([
        'tampered output' => static function (string $dir): BatchItem {
            file_put_contents($dir . '/out/a/definition.json', '{}');

            return batchItem($dir, 'a');
        },
        'extra output file' => static function (string $dir): BatchItem {
            file_put_contents($dir . '/out/a/extra.txt', 'x');

            return batchItem($dir, 'a');
        },
        'changed configuration' => static fn (string $dir): BatchItem => batchItem($dir, 'a', 7),
        'changed source content' => static function (string $dir): BatchItem {
            imagepng(Fixtures::coordinateImage(96, 72, true), $dir . '/photo.png');

            return batchItem($dir, 'a');
        },
    ]);

    it('changes a resumed item only when the source content changed', function (): void {
        $dir = batchDir();
        $options = new BatchOptions(checkpoint: $dir . '/batch.checkpoint.json');
        $first = runBatch([batchItem($dir, 'a')], $options);
        imagepng(Fixtures::coordinateImage(96, 72, true), $dir . '/photo.png');
        $second = runBatch([batchItem($dir, 'a')], $options);
        expect(statuses($second))->toBe(['done'])
            ->and($second->items[0]->files['source.png'])->not->toBe($first->items[0]->files['source.png']);
    });

    it('clear an output left by an interrupted run of the same batch', function (): void {
        $dir = batchDir();
        $checkpoint = $dir . '/batch.checkpoint.json';
        $item = batchItem($dir, 'a');
        (new ImagePuzzle\Batch\Checkpoint($checkpoint))->markStarted($item);
        mkdir($item->output, 0o755, true);
        file_put_contents($item->output . '/half.png', 'partial');
        $result = runBatch([$item], new BatchOptions(checkpoint: $checkpoint));
        expect(statuses($result))->toBe(['done'])
            ->and(file_exists($item->output . '/half.png'))->toBeFalse();
    });

    it('refuse a file that is not a checkpoint', function (): void {
        $dir = batchDir();
        file_put_contents($dir . '/other.json', '{"format":"something-else"}');
        expect(fn () => runBatch([batchItem($dir, 'a')], new BatchOptions(checkpoint: $dir . '/other.json')))
            ->toThrow(ImagePuzzle\Exception\StorageException::class, 'not a version 1 batch checkpoint');
    });
});

describe('concurrency', function (): void {
    it('builds items in parallel worker processes with the same results', function (): void {
        $dir = batchDir();
        $items = [batchItem($dir, 'a'), batchItem($dir, 'broken', source: $dir . '/missing.png'), batchItem($dir, 'c', 3), batchItem($dir, 'd', 4)];
        $events = [];
        $result = runBatch($items, new BatchOptions(concurrency: 2, checkpoint: $dir . '/batch.checkpoint.json'), $events);
        expect($result->status)->toBe(BatchResult::PARTIAL)
            ->and(statuses($result))->toBe(['done', 'failed', 'done', 'done'])
            ->and($result->items[1]->error)->toContain('missing.png')
            ->and(file_exists($dir . '/out/broken'))->toBeFalse();
        $ends = array_values(array_filter($events, static fn (BatchEvent $e): bool => $e->type === 'item-end'));
        expect(array_map(static fn (BatchEvent $e): int => $e->completed, $ends))->toBe([1, 2, 3, 4]);

        $sequential = batchDir();
        $reference = runBatch([batchItem($sequential, 'a')]);
        expect($result->items[0]->files)->toBe($reference->items[0]->files);
        expect(statuses(runBatch($items, new BatchOptions(concurrency: 2, checkpoint: $dir . '/batch.checkpoint.json'))))->toBe(['resumed', 'failed', 'resumed', 'resumed']);
    })->skip(!extension_loaded('pcntl') || PHP_SAPI !== 'cli', 'pcntl and CLI required');

    it('fails the running items instead of spinning when waiting for workers fails', function (): void {
        $dir = batchDir();
        $items = [batchItem($dir, 'a'), batchItem($dir, 'b', 2), batchItem($dir, 'c', 3)];
        // A hang would otherwise block the suite; the alarm kills the process.
        pcntl_alarm(30);
        try {
            $result = (new BatchRunner(new DefinitionBuilder(), null, new BatchWaitFailsOnce()))->run($items, new BatchOptions(concurrency: 2));
        } finally {
            pcntl_alarm(0);
        }
        $status = 0;

        expect(statuses($result))->toBe(['failed', 'failed', 'done'])
            ->and($result->items[0]->error)->toContain('waitpid failed')
            ->and(file_exists($dir . '/out/a'))->toBeFalse()
            ->and(pcntl_waitpid(-1, $status, WNOHANG))->toBe(-1);
    })->skip(!extension_loaded('pcntl') || PHP_SAPI !== 'cli', 'pcntl and CLI required');
});

/** Real process control whose first wait() fails as if waitpid returned -1. */
final class BatchWaitFailsOnce implements ProcessControl
{
    private bool $failed = false;
    private readonly PcntlProcessControl $inner;

    public function __construct()
    {
        $this->inner = new PcntlProcessControl();
    }

    public function isAvailable(): bool
    {
        return $this->inner->isAvailable();
    }

    public function fork(): int
    {
        return $this->inner->fork();
    }

    public function wait(int $pid): array
    {
        if (!$this->failed) {
            $this->failed = true;

            return ['pid' => -1, 'status' => 0, 'interrupted' => false];
        }

        return $this->inner->wait($pid);
    }

    public function signal(int $pid, int $signal): bool
    {
        return $this->inner->signal($pid, $signal);
    }

    public function exitedNormally(int $status): bool
    {
        return $this->inner->exitedNormally($status);
    }

    public function exitCode(int $status): int
    {
        return $this->inner->exitCode($status);
    }

    public function termSignal(int $status): ?int
    {
        return $this->inner->termSignal($status);
    }

    public function terminateChild(int $code): never
    {
        $this->inner->terminateChild($code);
    }
}
