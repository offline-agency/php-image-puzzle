<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\Export\ProcessControl;
use ImagePuzzle\Exporter;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\Tests\Support\Fixtures;
use ImagePuzzle\Tests\Support\ManifestAssertions;

/*
 * Without process control (no pcntl/posix, or a non-CLI SAPI) parallel export
 * falls back to the sequential path with identical output. Runs everywhere,
 * including the minimal image without pcntl.
 */

final class NoProcessControl implements ProcessControl
{
    public int $forkCalls = 0;

    public function isAvailable(): bool
    {
        return false;
    }

    public function fork(): int
    {
        $this->forkCalls++;

        return -1;
    }

    public function wait(int $pid): array
    {
        return ['pid' => -1, 'status' => 0, 'interrupted' => false];
    }

    public function signal(int $pid, int $signal): bool
    {
        return false;
    }

    public function exitedNormally(int $status): bool
    {
        return false;
    }

    public function exitCode(int $status): int
    {
        return 1;
    }

    public function termSignal(int $status): ?int
    {
        return null;
    }

    public function terminateChild(int $code): never
    {
        throw new LogicException('terminateChild must not be called without process control');
    }
}

test('parallel export without process control is sequential and byte-identical', function (): void {
    $dir = Fixtures::tempDir('fallback');
    try {
        $source = Fixtures::writePng(Fixtures::coordinateImage(90, 60), $dir . '/s.png');
        $control = new NoProcessControl();
        $parallel = (new Exporter($control))->exportParallel($source, $dir . '/par', 3, 4, new ExportOptions(), 4, new JigsawStyle());
        $sequential = PuzzleGenerator::generateAndExport($source, $dir . '/seq', 3, 4, new JigsawStyle());

        expect($control->forkCalls)->toBe(0);
        expect($parallel->getWorkerMemory())->toBe([]);
        ManifestAssertions::assertConsistent($parallel->getManifestPath());
        expect(ManifestAssertions::snapshot($dir . '/par'))->toBe(ManifestAssertions::snapshot($dir . '/seq'));
        expect($sequential->getFiles())->toHaveCount(13);
    } finally {
        Fixtures::removeDir($dir);
    }
});

test('the real process control reports availability consistently with the runtime', function (): void {
    $available = (new ImagePuzzle\Export\PcntlProcessControl())->isAvailable();
    expect($available)->toBe(function_exists('pcntl_fork') && function_exists('posix_kill') && PHP_SAPI === 'cli');
});
