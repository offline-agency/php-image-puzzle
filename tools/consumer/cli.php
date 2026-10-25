<?php

declare(strict_types=1);

/*
 * Runs inside the throwaway consumer project (see run.sh) and drives the
 * installed vendor/bin/image-puzzle like a user would: every command, the
 * exit codes for malformed input, unsafe paths and resource limits, and the
 * player script the ZIP bundles copy from the installed package.
 *
 * BUNDLE_OUT=<dir> keeps the generated bundle.zip there, so the browser
 * suite can open what the installed package produced.
 */

$bin = getcwd() . '/vendor/bin/image-puzzle';
$package = getcwd() . '/vendor/offline-agency/image-puzzle';
$work = sys_get_temp_dir() . '/image-puzzle-cli-' . bin2hex(random_bytes(6));
mkdir($work, 0o700);

final class Checks
{
    public static int $failures = 0;
}

/**
 * @param list<string> $args
 *
 * @return array{0: int, 1: string, 2: string}
 */
function run(string $bin, array $args): array
{
    $process = proc_open([PHP_BINARY, $bin, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start image-puzzle');
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $out, $err];
}

/**
 * @param list<string> $args
 */
function check(string $label, string $bin, array $args, int $code, string $contains = ''): string
{
    [$actual, $out, $err] = run($bin, $args);
    if ($actual !== $code || ($contains !== '' && !str_contains($out . $err, $contains))) {
        fwrite(STDERR, "FAIL  {$label}: exit {$actual} (expected {$code})" . ($contains !== '' ? ", expected output containing \"{$contains}\"" : '') . "\n{$out}{$err}\n");
        Checks::$failures++;
    } else {
        echo "ok    {$label}\n";
    }

    return $out;
}

function removeTree(string $dir): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        if ($entry instanceof SplFileInfo) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
    }
    rmdir($dir);
}

try {
    $image = imagecreatetruecolor(320, 240);
    for ($y = 0; $y < 240; $y += 20) {
        imagefilledrectangle($image, 0, $y, 319, $y + 19, (int) imagecolorallocate($image, $y, 255 - $y, 120));
    }
    imagepng($image, "{$work}/photo.png");

    if (!is_file("{$package}/resources/dist/image-puzzle-player.iife.js")) {
        fwrite(STDERR, "FAIL  the installed package has no resources/dist/image-puzzle-player.iife.js\n");
        Checks::$failures++;
    }
    check('--version reports the installed version', $bin, ['--version'], 0, 'image-puzzle 2.99.99');
    check('help lists the commands', $bin, ['help'], 0, 'bundle');
    check('capabilities', $bin, ['capabilities'], 0, 'Encode:');

    check('generate --dry-run', $bin, ['generate', "{$work}/photo.png", "{$work}/puzzle", '--rows=3', '--cols=4', '--dry-run'], 0, '12 tiles');
    if (file_exists("{$work}/puzzle")) {
        fwrite(STDERR, "FAIL  --dry-run created the output directory\n");
        Checks::$failures++;
    }
    check('generate', $bin, ['generate', "{$work}/photo.png", "{$work}/puzzle", '--rows=3', '--cols=4', '--seed=7', '--resolutions=small:160', '--atlas=small'], 0, 'Built jigsaw puzzle');
    file_put_contents("{$work}/build.json", '{"format":"image-puzzle/build-config","version":1,"kind":"sliding","preset":"easy","seed":2}');
    check('generate --config', $bin, ['generate', "{$work}/photo.png", "{$work}/sliding", "--config={$work}/build.json"], 0, 'Built sliding puzzle');
    check('validate', $bin, ['validate', "{$work}/puzzle"], 0, 'Valid jigsaw definition');
    // Every mode the installed CLI advertises can be generated and validated.
    $capabilities = json_decode(run($bin, ['capabilities', '--json'])[1], true);
    $modes = is_array($capabilities) && is_array($capabilities['modes'] ?? null) ? $capabilities['modes'] : [];
    if ($modes === []) {
        fwrite(STDERR, "FAIL  capabilities --json lists no modes\n");
        Checks::$failures++;
    }
    foreach ($modes as $mode) {
        $mode = is_string($mode) ? $mode : '';
        check("generate --kind={$mode}", $bin, ['generate', "{$work}/photo.png", "{$work}/mode-{$mode}", "--kind={$mode}", '--preset=easy'], 0, "Built {$mode} puzzle");
        check("validate {$mode}", $bin, ['validate', "{$work}/mode-{$mode}"], 0, "Valid {$mode} definition");
    }
    check('inspect', $bin, ['inspect', "{$work}/puzzle"], 0, 'all present');
    check('reconstruct', $bin, ['reconstruct', "{$work}/puzzle", "{$work}/board.png"], 0, 'Wrote');
    check('svg', $bin, ['svg', "{$work}/puzzle", "{$work}/puzzle.svg", '--image=embed'], 0, 'Wrote');
    check('pdf', $bin, ['pdf', "{$work}/puzzle", "{$work}/puzzle.pdf", '--paper=a4'], 0, '2 page(s)');
    if (in_array('nonogram', $modes, true)) {
        // Printing a nonogram's picture would print its solution.
        check('svg refuses a nonogram', $bin, ['svg', "{$work}/mode-nonogram", "{$work}/nonogram.svg"], 3, 'no pieces to cut');
    }
    if (class_exists(ZipArchive::class)) {
        check('bundle', $bin, ['bundle', "{$work}/puzzle", "{$work}/bundle.zip"], 0, 'image-puzzle/index.html');
        $zip = new ZipArchive();
        $zip->open("{$work}/bundle.zip", ZipArchive::RDONLY);
        $player = $zip->getFromName('image-puzzle/player/image-puzzle-player.iife.js');
        if ($player !== file_get_contents("{$package}/resources/dist/image-puzzle-player.iife.js")) {
            fwrite(STDERR, "FAIL  the bundle does not hold the installed player script\n");
            Checks::$failures++;
        }
        $zip->close();
        $keep = getenv('BUNDLE_OUT');
        if (is_string($keep) && $keep !== '') {
            copy("{$work}/bundle.zip", rtrim($keep, '/') . '/bundle.zip');
        }
    } else {
        check('bundle without ext-zip', $bin, ['bundle', "{$work}/puzzle", "{$work}/bundle.zip"], 4, 'ext-zip');
    }

    // Malformed input, unsafe paths and limits.
    check('unknown option', $bin, ['generate', "{$work}/photo.png", "{$work}/x", '--colour=red'], 2, 'Unknown option --colour');
    file_put_contents("{$work}/fake.png", 'not an image');
    check('malformed image', $bin, ['generate', "{$work}/fake.png", "{$work}/x"], 3);
    // A GIF header claiming 25120x29813 pixels is refused before decoding.
    file_put_contents("{$work}/huge.gif", 'GIF89a but not really');
    check('image dimension budget', $bin, ['generate', "{$work}/huge.gif", "{$work}/x"], 5, 'configured maximum');
    check('non-empty output directory', $bin, ['generate', "{$work}/photo.png", "{$work}/puzzle"], 3, 'must be empty');
    file_put_contents("{$work}/bad.json", '{"format": "image-puzzle/build-config",');
    check('malformed configuration', $bin, ['generate', "{$work}/photo.png", "{$work}/x", "--config={$work}/bad.json"], 3);
    check('piece budget', $bin, ['generate', "{$work}/photo.png", "{$work}/x", '--rows=60', '--cols=60', '--dry-run'], 5, '2500');
    check('existing target', $bin, ['svg', "{$work}/puzzle", "{$work}/puzzle.svg"], 1, 'must not exist');
    $definition = (string) file_get_contents("{$work}/puzzle/definition.json");
    file_put_contents("{$work}/puzzle/definition.json", str_replace('"image": "source.png"', '"image": "../photo.png"', $definition));
    check('definition with a parent path', $bin, ['validate', "{$work}/puzzle"], 3, 'dot segments');
    file_put_contents("{$work}/puzzle/definition.json", $definition);
    $pieces = glob("{$work}/puzzle/pieces/*.png");
    $piece = $pieces === false ? '' : ($pieces[0] ?? '');
    unlink($piece);
    symlink("{$work}/photo.png", $piece);
    check('piece symlinked outside the puzzle', $bin, ['inspect', "{$work}/puzzle"], 3, 'outside the puzzle directory');
} finally {
    removeTree($work);
}

if (Checks::$failures > 0) {
    fwrite(STDERR, Checks::$failures . " CLI check(s) failed\n");
    exit(1);
}
echo "installed CLI checks passed\n";
