<?php

declare(strict_types=1);

use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * The example scripts listed in docs/EXAMPLES.md run against the current
 * code: the plain cutting script, the edge-sample generator and the demo
 * site builder.
 */

/**
 * @param list<string> $args
 *
 * @return array{0: int, 1: string}
 */
function runExample(string $script, array $args): array
{
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/examples/' . $script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException("Cannot start {$script}");
    }
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $output];
}

it('cuts and exports with examples/generate.php', function (): void {
    $dir = Fixtures::tempDir('ip-example-');
    try {
        imagepng(Fixtures::coordinateImage(240, 180, false), "{$dir}/photo.png");

        [$code, $output] = runExample('generate.php', ["{$dir}/photo.png", '3', '4', "{$dir}/out", '--rounded=6']);

        expect($code)->toBe(0, $output)
            ->and($output)->toContain('Exported 12 pieces')
            ->and("{$dir}/out/manifest.json")->toBeFile();
        [$code] = runExample('generate.php', ["{$dir}/photo.png", '0', '4', "{$dir}/zero"]);
        expect($code)->toBe(1);
    } finally {
        Fixtures::removeDir($dir);
    }
});

it('writes one sample per edge type with examples/generate-edge-samples.php', function (): void {
    $dir = Fixtures::tempDir('ip-example-');
    try {
        imagepng(Fixtures::coordinateImage(200, 200, false), "{$dir}/photo.png");

        [$code, $output] = runExample('generate-edge-samples.php', ["{$dir}/photo.png", "{$dir}/edges"]);

        $samples = glob("{$dir}/edges/*/manifest.json");
        expect($code)->toBe(0, $output)
            ->and($samples === false ? 0 : count($samples))->toBeGreaterThanOrEqual(5);
    } finally {
        Fixtures::removeDir($dir);
    }
});

it('builds a demo site for every mode with examples/demo/build.php', function (): void {
    $dir = Fixtures::tempDir('ip-example-');
    try {
        imagepng(Fixtures::coordinateImage(320, 240, false), "{$dir}/photo.png");

        [$code, $output] = runExample('demo/build.php', ["{$dir}/site", "{$dir}/photo.png", '--title=<Team & "friends">', '--seed=3']);

        expect($code)->toBe(0, $output)->and("{$dir}/site/image-puzzle-player.iife.js")->toBeFile();
        $page = (string) file_get_contents("{$dir}/site/index.html");
        expect($page)->toContain('<title>&lt;Team &amp; &quot;friends&quot;&gt;</title>')
            ->and($page)->not->toContain('<Team');
        preg_match_all('/data-mode="([a-z]+)"/', $page, $links);
        expect($links[1])->not->toBe([]);
        foreach ($links[1] as $mode) {
            $definition = GameDefinition::fromJson((string) file_get_contents("{$dir}/site/{$mode}/definition.json"));
            expect($definition->kind->value)->toBe($mode);
        }
    } finally {
        Fixtures::removeDir($dir);
    }
});

it('refuses a non-empty output directory and bad options in the demo builder', function (string $args, string $message): void {
    $dir = Fixtures::tempDir('ip-example-');
    try {
        imagepng(Fixtures::coordinateImage(64, 64, false), "{$dir}/photo.png");
        mkdir("{$dir}/used");
        touch("{$dir}/used/keep.txt");

        [$code, $output] = runExample('demo/build.php', explode(' ', str_replace('{dir}', $dir, $args)));

        expect($code)->toBe(1)->and($output)->toContain($message);
    } finally {
        Fixtures::removeDir($dir);
    }
})->with([
    'non-empty output' => ['{dir}/used {dir}/photo.png', 'must be new or empty'],
    'unknown preset' => ['{dir}/site {dir}/photo.png --preset=extreme', '--preset must be'],
    'unknown option' => ['{dir}/site {dir}/photo.png --colour=red', 'Unknown option --colour'],
    'missing image' => ['{dir}/site {dir}/nope.png', 'Image not found'],
]);
