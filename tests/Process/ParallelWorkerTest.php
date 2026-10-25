<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\Export\PcntlProcessControl;
use ImagePuzzle\Export\ProcessControl;
use ImagePuzzle\Exporter;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\GeometryOptions;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\Tests\Support\Fixtures;
use ImagePuzzle\Tests\Support\Workspace;
use ImagePuzzle\Tests\Support\ManifestAssertions;

/*
 * Real forked-process tests (G08, G09, G10, G11, sequential/parallel
 * equivalence, reaping). They run in the dedicated Linux CI job with
 * REQUIRE_PCNTL=1, where a missing pcntl extension is a failure, not a skip.
 */

$pcntl = function_exists('pcntl_fork') && PHP_SAPI === 'cli';
if (!$pcntl && getenv('REQUIRE_PCNTL') === '1') {
    throw new RuntimeException('REQUIRE_PCNTL=1 but ext-pcntl is not available in the CLI.');
}

final class ForkFailsAfter implements ProcessControl
{
    private int $forks = 0;
    private readonly PcntlProcessControl $inner;

    public function __construct(private readonly int $succeed)
    {
        $this->inner = new PcntlProcessControl();
    }

    public function isAvailable(): bool
    {
        return $this->inner->isAvailable();
    }

    public function fork(): int
    {
        return $this->forks++ < $this->succeed ? $this->inner->fork() : -1;
    }

    public function wait(int $pid): array
    {
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

function noChildrenLeft(): bool
{
    $status = 0;

    return pcntl_waitpid(-1, $status, WNOHANG) === -1;
}

/**
 * @return list<string>
 */
function nonEmptyLines(string $file): array
{
    $raw = file_get_contents($file);

    return array_values(array_filter(explode("\n", $raw === false ? '' : $raw), static fn (string $line): bool => $line !== ''));
}

/**
 * @param array<mixed> $envelope
 * @return list<array<mixed>>
 */
function envelopePieces(array $envelope): array
{
    $pieces = $envelope['pieces'] ?? null;
    if (!is_array($pieces) || !array_is_list($pieces)) {
        throw new RuntimeException('Envelope has no piece list');
    }

    return array_values(array_filter($pieces, is_array(...)));
}

beforeEach(function (): void {
    Workspace::$dir = Fixtures::tempDir();
    Workspace::$source = Fixtures::writePng(Fixtures::coordinateImage(96, 72), Workspace::$dir . '/source.png');
    Workspace::$parent = Fixtures::pid();
});

afterEach(function (): void {
    if (getmypid() !== Workspace::$parent) {
        // Defensive: a child must never get here.
        posix_kill(Fixtures::pid(), SIGKILL);
    }
    Fixtures::removeDir(Workspace::$dir);
});

test('a throwing callback never returns into the caller in a child (PID sentinel)', function (): void {
    $sentinel = Workspace::$dir . '/post-catch.log';
    try {
        (new Exporter())->exportParallel(Workspace::$source, Workspace::$dir . '/out', 3, 4, new ExportOptions(onPieceBeforeSave: static function (): void {
            throw new RuntimeException('callback failure');
        }), 3);
        $reached = 'no-exception';
    } catch (Throwable $e) {
        $reached = $e::class;
    }
    file_put_contents($sentinel, getmypid() . "\n", FILE_APPEND);
    expect($reached)->toBe(ExportFailedException::class);
    expect(nonEmptyLines($sentinel))->toBe([(string) Workspace::$parent]);
    expect(file_exists(Workspace::$dir . '/out/manifest.json'))->toBeFalse();
    expect(noChildrenLeft())->toBeTrue();
})->group('process');

test('a worker killed by a signal fails the export and publishes no manifest', function (): void {
    $parent = Workspace::$parent;
    expect(fn () => (new Exporter())->exportParallel(Workspace::$source, Workspace::$dir . '/out', 3, 4, new ExportOptions(onPieceBeforeSave: static function () use ($parent): void {
        if (getmypid() !== $parent) {
            posix_kill(Fixtures::pid(), SIGKILL);
        }
    }), 2))->toThrow(ExportFailedException::class, 'killed by signal 9');
    expect(file_exists(Workspace::$dir . '/out/manifest.json'))->toBeFalse();
    expect(Fixtures::glob(Workspace::$dir . '/out/*'))->toBe([]);
    expect(noChildrenLeft())->toBeTrue();
})->group('process');

test('a nonzero worker exit without envelope fails the export', function (): void {
    $parent = Workspace::$parent;
    expect(fn () => (new Exporter())->exportParallel(Workspace::$source, Workspace::$dir . '/out', 2, 2, new ExportOptions(onPieceBeforeSave: static function () use ($parent): void {
        if (getmypid() !== $parent) {
            exit(3);
        }
    }), 2))->toThrow(ExportFailedException::class, 'exit code 3');
    expect(file_exists(Workspace::$dir . '/out/manifest.json'))->toBeFalse();
})->group('process');

test('invalid worker envelopes are failures', function (Closure $filter, string $message): void {
    $exporter = new Exporter(null, $filter);
    expect(fn () => $exporter->exportParallel(Workspace::$source, Workspace::$dir . '/out', 2, 3, new ExportOptions(), 2))
        ->toThrow(ExportFailedException::class, $message);
    expect(file_exists(Workspace::$dir . '/out/manifest.json'))->toBeFalse();
    expect(noChildrenLeft())->toBeTrue();
})->with([
    'invalid JSON' => [static fn (array $e, int $i): string => '{not json', 'invalid JSON'],
    'empty payload' => [static fn (array $e, int $i): string => '', 'empty result envelope'],
    'missing piece' => [static function (array $e, int $i): string {
        $pieces = envelopePieces($e);
        array_pop($pieces);
        $e['pieces'] = $pieces;

        return json_encode($e, JSON_THROW_ON_ERROR);
    }, 'missing pieces'],
    'duplicate result' => [static function (array $e, int $i): string {
        $pieces = envelopePieces($e);
        $pieces[] = $pieces[0];
        $e['pieces'] = $pieces;

        return json_encode($e, JSON_THROW_ON_ERROR);
    }, 'duplicate result'],
    'foreign piece' => [static function (array $e, int $i): string {
        $pieces = envelopePieces($e);
        $pieces[0]['id'] = 'r9c9';
        $e['pieces'] = $pieces;

        return json_encode($e, JSON_THROW_ON_ERROR);
    }, 'unexpected piece'],
    'wrong worker' => [static function (array $e, int $i): string {
        $e['worker'] = $i + 10;

        return json_encode($e, JSON_THROW_ON_ERROR);
    }, 'malformed result envelope'],
])->group('process');

test('fork failure after one worker started terminates and reaps it with no orphan writes', function (): void {
    $out = Workspace::$dir . '/out';
    $slow = static function (): void {
        usleep(200_000);
    };
    expect(fn () => (new Exporter(new ForkFailsAfter(1)))->exportParallel(Workspace::$source, $out, 3, 4, new ExportOptions(onPieceBeforeSave: $slow), 3))
        ->toThrow(ExportFailedException::class, 'Failed to fork worker 2');
    expect(noChildrenLeft())->toBeTrue();
    $after = ManifestAssertions::snapshot($out);
    usleep(500_000);
    expect(ManifestAssertions::snapshot($out))->toBe($after)->toBe([]);
})->group('process');

test('parallel output is byte-identical to sequential output', function (string $style): void {
    $edge = $style === 'jigsaw' ? new JigsawStyle() : null;
    $geometry = new GeometryOptions(17);
    $seq = (new PuzzleGenerator($edge, geometry: $geometry))->generateFromFile(Workspace::$source, 3, 4)->export(Workspace::$dir . '/seq', new ExportOptions());
    $par = (new Exporter())->exportParallel(Workspace::$source, Workspace::$dir . '/par', 3, 4, new ExportOptions(), 3, $edge, null, $geometry);
    expect($par->getManifest())->toBe($seq->getManifest());
    expect(ManifestAssertions::snapshot(Workspace::$dir . '/par'))->toBe(ManifestAssertions::snapshot(Workspace::$dir . '/seq'));
    ManifestAssertions::assertConsistent($par->getManifestPath());
})->with(['rect', 'jigsaw'])->group('process');

test('parallel export honours overwrite=false exactly like sequential export', function (): void {
    $out = Workspace::$dir . '/out';
    (new Exporter())->exportParallel(Workspace::$source, $out, 2, 2, new ExportOptions(), 2);
    $piece = $out . '/piece_1_1.png';
    [$width, $height] = Fixtures::size($piece);
    $sentinel = Fixtures::canvas($width, $height);
    imagefill($sentinel, 0, 0, Fixtures::color($sentinel, 7, 7, 7));
    imagepng($sentinel, $piece);
    $hash = sha1_file($piece);

    $result = (new Exporter())->exportParallel(Workspace::$source, $out, 2, 2, new ExportOptions(overwrite: false), 2);
    expect(sha1_file($piece))->toBe($hash);
    expect($result->getReusedFiles())->toHaveCount(4);

    file_put_contents($piece, 'garbage');
    expect(fn () => (new Exporter())->exportParallel(Workspace::$source, $out, 2, 2, new ExportOptions(overwrite: false), 2))
        ->toThrow(ExportFailedException::class, 'Existing output cannot be used');
})->group('process');

test('repeated parallel jobs leave no zombies and no steadily growing memory', function (): void {
    $usage = [];
    for ($i = 0; $i < 6; $i++) {
        (new Exporter())->exportParallel(Workspace::$source, Workspace::$dir . '/out', 3, 3, new ExportOptions(), 3);
        expect(noChildrenLeft())->toBeTrue();
        gc_collect_cycles();
        $usage[] = memory_get_usage();
    }
    // After warm-up, memory must not grow by more than 256 KiB across jobs.
    expect($usage[5] - $usage[1])->toBeLessThan(256 * 1024);
})->group('process');

test('worker count is capped by the aggregate memory budget', function (): void {
    $limits = new \ImagePuzzle\ResourceLimits(workerMemoryBudget: 96 * 72 * 5 * 2 * 2);
    expect($limits->workerCapacity(8, 12, 96, 72))->toBe(2);
    expect((new \ImagePuzzle\ResourceLimits(workerMemoryBudget: 10))->workerCapacity(8, 12, 96, 72))->toBe(0);
    // A budget too small for two workers falls back to sequential export.
    $result = (new Exporter())->exportParallel(Workspace::$source, Workspace::$dir . '/out', 2, 2, new ExportOptions(), 4, null, null, null, new \ImagePuzzle\ResourceLimits(workerMemoryBudget: 10));
    ManifestAssertions::assertConsistent($result->getManifestPath());
})->group('process');

test('callbacks: before hooks run in workers, after hooks in the parent', function (): void {
    $log = Workspace::$dir . '/hooks.log';
    $parent = Workspace::$parent;
    (new Exporter())->exportParallel(Workspace::$source, Workspace::$dir . '/out', 2, 2, new ExportOptions(
        onPieceBeforeSave: static function () use ($log, $parent): void {
            file_put_contents($log, 'before:' . (getmypid() === $parent ? 'parent' : 'child') . "\n", FILE_APPEND);
        },
        onPieceAfterSave: static function () use ($log, $parent): void {
            file_put_contents($log, 'after:' . (getmypid() === $parent ? 'parent' : 'child') . "\n", FILE_APPEND);
        },
    ), 2);
    $lines = array_count_values(nonEmptyLines($log));
    expect($lines)->toBe(['before:child' => 4, 'after:parent' => 4]);
})->group('process');
