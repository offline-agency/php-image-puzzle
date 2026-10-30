<?php

declare(strict_types=1);

use ImagePuzzle\Tests\Support\Fixtures;

/*
 * The README images come from examples/showcase.php. A fresh run must
 * reassemble the source exactly, and the committed images must match it
 * (the script scales without imagescale(), so builds agree; the small
 * tolerance only absorbs alpha-blending rounding between GD builds).
 */

/**
 * Largest channel difference and mean absolute difference over all pixels.
 *
 * @return array{max: int, mean: float}
 */
function imageDifference(string $a, string $b): array
{
    $left = imagecreatefrompng($a);
    $right = imagecreatefrompng($b);
    if ($left === false || $right === false) {
        throw new RuntimeException("Cannot read {$a} or {$b}.");
    }
    expect([imagesx($left), imagesy($left)])->toBe([imagesx($right), imagesy($right)], basename($a) . ' changed size');
    $max = 0;
    $total = 0;
    for ($y = 0; $y < imagesy($left); $y++) {
        for ($x = 0; $x < imagesx($left); $x++) {
            $p = imagecolorat($left, $x, $y);
            $q = imagecolorat($right, $x, $y);
            foreach ([24, 16, 8, 0] as $shift) {
                $d = abs((($p >> $shift) & 0xFF) - (($q >> $shift) & 0xFF));
                $max = max($max, $d);
                $total += $d;
            }
        }
    }

    return ['max' => $max, 'mean' => $total / (4 * imagesx($left) * imagesy($left))];
}

it('reassembles the source exactly and matches the committed images', function (): void {
    $dir = Fixtures::tempDir('ip-showcase-');
    try {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/examples/showcase.php', $dir], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run examples/showcase.php.');
        }
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        expect(proc_close($process))->toBe(0, $output);

        expect(imageDifference($dir . '/source.png', $dir . '/reassembled.png')['max'])->toBe(0);
        $committed = dirname(__DIR__, 2) . '/docs/images/showcase';
        foreach (['source', 'reassembled', 'exploded-rect', 'exploded-hex', 'exploded-triangle', 'overview'] as $name) {
            $difference = imageDifference("{$committed}/{$name}.png", "{$dir}/{$name}.png");
            expect($difference['mean'])->toBeLessThan(1.0, "{$name}.png differs from a fresh run of examples/showcase.php; regenerate it");
        }
    } finally {
        Fixtures::removeDir($dir);
    }
});
