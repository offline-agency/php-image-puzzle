<?php

declare(strict_types=1);

/*
 * Builds the release artifacts of a checked-out commit (read-only job,
 * after composer install, npm ci and npm run build in that checkout). The
 * workflow runs this script from the trusted tooling revision with --root
 * pointing at the checkout of the accepted commit.
 *   php tools/release/build-artifacts.php --sha=SHA --version=X.Y.Z --out=DIR
 *       [--root=DIR] [--skip-npm]
 */

use ImagePuzzle\Game\Modes;
use ImagePuzzle\Release\ArtifactBuilder;
use ImagePuzzle\Release\Cli;
use ImagePuzzle\Release\PolicyViolation;
use ImagePuzzle\Release\Version;

require __DIR__ . '/autoload.php';

try {
    $options = Cli::options(Cli::argv());
    $root = rtrim($options['root'] ?? Cli::root(), '/');
    $sha = Cli::sha($options['sha'] ?? '', '--sha');
    $version = Version::parse($options['version'] ?? '') ?? throw new PolicyViolation('--version must be X.Y.Z');
    $out = $options['out'] ?? throw new PolicyViolation('--out is required');
    // The library of the built commit, only to list the modes it ships.
    require $root . '/vendor/autoload.php';
    $modes = array_map(static fn ($kind): string => $kind->value, Modes::available());

    $set = (new ArtifactBuilder($root))->build($sha, $version, $out, $modes, !isset($options['skip-npm']));
    foreach ($set->files as $file) {
        echo "{$file['sha256']}  {$file['name']}\n";
    }
} catch (PolicyViolation|JsonException|RuntimeException $e) {
    fwrite(STDERR, "artifact build failed: {$e->getMessage()}\n");
    exit(1);
}
