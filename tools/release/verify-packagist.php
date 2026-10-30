<?php

declare(strict_types=1);

/*
 * Verifies distribution after a release: waits (bounded, with backoff) until
 * the Composer repository lists the version at the accepted commit, then
 * installs exactly that version in a fresh consumer project without any path
 * repository and builds a puzzle with it. Runs in an unprivileged job; a
 * failure leaves the GitHub Release untouched and can be retried as is.
 *   php tools/release/verify-packagist.php --version=X.Y.Z --sha=SHA
 *       [--repository=https://repo.packagist.org | file:///fixture]
 *       [--attempts=10] [--first-delay=15] [--no-install]
 */

use ImagePuzzle\Release\Cli;
use ImagePuzzle\Release\Http\CurlHttp;
use ImagePuzzle\Release\Http\FileHttp;
use ImagePuzzle\Release\PackagistVerifier;
use ImagePuzzle\Release\PolicyViolation;
use ImagePuzzle\Release\Version;

require __DIR__ . '/autoload.php';

/**
 * @param list<string> $command
 */
function verifyRun(array $command, string $cwd): void
{
    $process = proc_open($command, [1 => STDOUT, 2 => STDERR], $pipes, $cwd);
    if (!is_resource($process) || proc_close($process) !== 0) {
        throw new RuntimeException(implode(' ', $command) . ' failed');
    }
}

try {
    $options = Cli::options(Cli::argv());
    $policy = Cli::policy($options['policy'] ?? null);
    $version = Version::parse($options['version'] ?? '') ?? throw new PolicyViolation('--version must be X.Y.Z');
    $sha = Cli::sha($options['sha'] ?? '', '--sha');
    $repository = $options['repository'] ?? 'https://repo.packagist.org';
    $http = str_starts_with($repository, 'file://') ? new FileHttp() : new CurlHttp();
    $verifier = new PackagistVerifier($http, $repository, max(1, (int) ($options['attempts'] ?? 10)), max(0, (int) ($options['first-delay'] ?? 15)));

    $result = $verifier->waitFor($policy->composerPackage, (string) $version, $sha);
    echo $result['detail'], " ({$result['attempts']} attempt(s))\n";
    if (!$result['found']) {
        throw new PolicyViolation('distribution not verified yet; the release stays as it is. Retry later with the same command, or check the Packagist webhook (docs/release/RUNBOOK.md)');
    }
    if (isset($options['no-install'])) {
        exit(0);
    }

    $work = sys_get_temp_dir() . '/image-puzzle-dist-' . bin2hex(random_bytes(6));
    mkdir($work, 0o700);
    register_shutdown_function(static fn () => verifyRun(['rm', '-rf', $work], sys_get_temp_dir()));
    $project = ['name' => 'example/dist-check', 'type' => 'project', 'require' => [$policy->composerPackage => (string) $version], 'config' => ['allow-plugins' => false]];
    if ($repository !== 'https://repo.packagist.org') {
        $project['repositories'] = [['type' => 'composer', 'url' => $repository], ['packagist.org' => false]];
    }
    file_put_contents("{$work}/composer.json", json_encode($project, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    file_put_contents("{$work}/check.php", <<<'PHP'
        <?php
        require __DIR__ . '/vendor/autoload.php';
        $image = imagecreatetruecolor(240, 160);
        imagefilledrectangle($image, 0, 0, 239, 159, (int) imagecolorallocate($image, 30, 120, 200));
        imagepng($image, __DIR__ . '/photo.png');
        $result = (new ImagePuzzle\PuzzleGenerator())->generateFromFile(__DIR__ . '/photo.png', 2, 3)->export(__DIR__ . '/out');
        $manifest = json_decode((string) file_get_contents($result->getManifestPath()), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || count($result->getFiles()) !== 7) {
            fwrite(STDERR, "unexpected export\n");
            exit(1);
        }
        echo "exported 6 pieces and a manifest with the installed package\n";
        PHP);
    verifyRun(['composer', 'install', '--no-interaction', '--no-progress', '--no-scripts'], $work);
    $installed = json_decode((string) file_get_contents("{$work}/vendor/composer/installed.json"), true, 64, JSON_THROW_ON_ERROR);
    $reference = null;
    foreach (is_array($installed) && is_array($installed['packages'] ?? null) ? $installed['packages'] : [] as $package) {
        if (is_array($package) && ($package['name'] ?? null) === $policy->composerPackage) {
            $reference = is_array($package['dist'] ?? null) ? ($package['dist']['reference'] ?? null) : null;
            $reference = is_array($package['source'] ?? null) && $reference === null ? ($package['source']['reference'] ?? null) : $reference;
        }
    }
    if ($reference !== $sha) {
        throw new PolicyViolation("installed {$policy->composerPackage} has reference " . (is_string($reference) ? $reference : 'none') . ", expected {$sha}");
    }
    verifyRun([PHP_BINARY, 'check.php'], $work);
    echo "{$policy->composerPackage} {$version} installs from {$repository} at {$sha}\n";
} catch (PolicyViolation|JsonException|RuntimeException $e) {
    fwrite(STDERR, "distribution check failed: {$e->getMessage()}\n");
    exit(1);
}
