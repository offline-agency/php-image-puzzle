<?php

declare(strict_types=1);

use ImagePuzzle\Release\ArtifactBuilder;
use ImagePuzzle\Release\Http\Http;
use ImagePuzzle\Release\Http\Response;
use ImagePuzzle\Release\PackagistVerifier;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * Distribution checks after a release: Composer metadata polling with
 * bounded backoff, an exact-version install from a local Composer
 * repository built from this commit, and the prebuilt browser assets that
 * Composer consumers receive.
 */

/**
 * @param list<Response|RuntimeException> $responses
 */
function scriptedHttp(array $responses): Http
{
    return new class ($responses) implements Http {
        /**
         * @param list<Response|RuntimeException> $responses
         */
        public function __construct(private array $responses)
        {
        }

        public function request(string $method, string $url, array $headers = [], ?string $body = null): Response
        {
            $next = array_shift($this->responses) ?? new Response(404, '');
            if ($next instanceof RuntimeException) {
                throw $next;
            }

            return $next;
        }
    };
}

function minifiedMetadata(string $version, string $sha): string
{
    return (string) json_encode(['minified' => 'composer/2.0', 'packages' => ['offline-agency/image-puzzle' => [
        ['name' => 'offline-agency/image-puzzle', 'version' => '1.0.2', 'source' => ['type' => 'git', 'reference' => str_repeat('c', 40)], 'dist' => ['type' => 'zip', 'reference' => str_repeat('c', 40)]],
        ['version' => $version, 'source' => ['type' => 'git', 'reference' => $sha], 'dist' => ['type' => 'zip', 'reference' => $sha]],
    ]]]);
}

/**
 * @param list<string>               $command
 * @param array<string, string>|null $env
 *
 * @return array{0: int, 1: string}
 */
function runCommand(array $command, string $cwd, ?array $env = null): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);
    if (!is_resource($process)) {
        throw new RuntimeException("cannot start {$command[0]}");
    }
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $output];
}

it('expands minified Composer 2 metadata', function (): void {
    $sha = str_repeat('d', 40);
    $metadata = json_decode(minifiedMetadata('1.0.3', $sha), true, 64, JSON_THROW_ON_ERROR);
    expect(is_array($metadata))->toBeTrue();
    if (!is_array($metadata)) {
        return;
    }

    expect(PackagistVerifier::find($metadata, 'offline-agency/image-puzzle', '1.0.3'))->toBe(['source' => $sha, 'dist' => $sha])
        ->and(PackagistVerifier::find($metadata, 'offline-agency/image-puzzle', '1.0.2'))->toBe(['source' => str_repeat('c', 40), 'dist' => str_repeat('c', 40)])
        ->and(PackagistVerifier::find($metadata, 'offline-agency/image-puzzle', '9.9.9'))->toBeNull();
});

it('polls with bounded exponential backoff until the version appears', function (): void {
    $sha = str_repeat('d', 40);
    $waits = [];
    $verifier = new PackagistVerifier(
        scriptedHttp([new RuntimeException('connection reset'), new Response(503, ''), new Response(200, minifiedMetadata('1.0.2', $sha)), new Response(200, minifiedMetadata('1.0.3', $sha))]),
        'https://repo.example',
        6,
        15,
        40,
        static function (int $seconds) use (&$waits): void {
            $waits[] = $seconds;
        },
    );

    $result = $verifier->waitFor('offline-agency/image-puzzle', '1.0.3', $sha);

    expect($result['found'])->toBeTrue()
        ->and($result['attempts'])->toBe(4)
        ->and($waits)->toBe([15, 30, 40]);
});

it('gives up after the last attempt and reports a reference mismatch at once', function (): void {
    $sha = str_repeat('d', 40);
    $slept = 0;
    $sleep = static function (int $seconds) use (&$slept): void {
        $slept += $seconds;
    };

    $unavailable = (new PackagistVerifier(scriptedHttp([]), 'https://repo.example', 3, 1, 300, $sleep))->waitFor('offline-agency/image-puzzle', '1.0.3', $sha);
    $mismatch = (new PackagistVerifier(scriptedHttp([new Response(200, minifiedMetadata('1.0.3', str_repeat('e', 40)))]), 'https://repo.example', 3, 1, 300, $sleep))->waitFor('offline-agency/image-puzzle', '1.0.3', $sha);

    expect($unavailable)->toBe(['found' => false, 'attempts' => 3, 'detail' => 'HTTP 404 from https://repo.example/p2/offline-agency/image-puzzle.json'])
        ->and($slept)->toBe(3)
        ->and($mismatch['found'])->toBeFalse()
        ->and($mismatch['attempts'])->toBe(1)
        ->and($mismatch['detail'])->toContain('not ' . $sha);
});

it('installs the exact version from a Composer repository and builds a puzzle with it', function (): void {
    $root = dirname(__DIR__, 2);
    $sha = trim(runCommand(['git', 'rev-parse', 'HEAD'], $root)[1]);
    $repo = Fixtures::tempDir('ip-composer-repo-');
    try {
        mkdir("{$repo}/p2/offline-agency", 0o755, true);
        [$archived, $output] = runCommand(['git', 'archive', '--format=zip', "--output={$repo}/package.zip", $sha], $root);
        expect($archived)->toBe(0, $output);
        $composer = Fixtures::json("{$root}/composer.json");
        $entry = ['name' => 'offline-agency/image-puzzle', 'version' => '9.9.9', 'version_normalized' => '9.9.9.0', 'type' => 'library',
            'dist' => ['type' => 'zip', 'url' => "file://{$repo}/package.zip", 'reference' => $sha],
            'require' => $composer['require'], 'autoload' => $composer['autoload']];
        file_put_contents("{$repo}/packages.json", json_encode(['packages' => [], 'metadata-url' => "file://{$repo}/p2/%package%.json"]));
        file_put_contents("{$repo}/p2/offline-agency/image-puzzle.json", json_encode(['minified' => 'composer/2.0', 'packages' => ['offline-agency/image-puzzle' => [$entry]]], JSON_UNESCAPED_SLASHES));

        $script = "{$root}/tools/release/verify-packagist.php";
        // A private Composer cache, so the fixture version never reaches the user's cache.
        $env = [...array_filter(getenv(), is_string(...)), 'COMPOSER_CACHE_DIR' => "{$repo}/cache"];
        [$code, $output] = runCommand([PHP_BINARY, $script, '--version=9.9.9', "--sha={$sha}", "--repository=file://{$repo}", '--attempts=1', '--first-delay=0'], $root, $env);
        [$wrong, $wrongOutput] = runCommand([PHP_BINARY, $script, '--version=9.9.9', '--sha=' . str_repeat('0', 40), "--repository=file://{$repo}", '--attempts=1', '--first-delay=0'], $root);
        [$missing, $missingOutput] = runCommand([PHP_BINARY, $script, '--version=9.9.10', "--sha={$sha}", "--repository=file://{$repo}", '--attempts=2', '--first-delay=0', '--no-install'], $root);

        expect($code)->toBe(0, $output)
            ->and($output)->toContain('exported 6 pieces')
            ->and($output)->toContain("offline-agency/image-puzzle 9.9.9 installs from file://{$repo} at {$sha}")
            ->and($wrong)->toBe(1, $wrongOutput)
            ->and($wrongOutput)->toContain('not ' . str_repeat('0', 40))
            ->and($missing)->toBe(1, $missingOutput)
            ->and($missingOutput)->toContain('9.9.10 is not listed yet (2 attempt(s))');
    } finally {
        Fixtures::removeDir($repo);
    }
})->skip(
    fn (): bool => runCommand(['git', 'rev-parse', 'HEAD'], dirname(__DIR__, 2))[0] !== 0 || runCommand(['composer', '--version'], dirname(__DIR__, 2))[0] !== 0,
    'needs a Git checkout and Composer',
);

it('refuses composer.json version fields that make Packagist skip the tag', function (): void {
    $root = Fixtures::tempDir('ip-composer-version-');
    try {
        mkdir("{$root}/integrations/livewire", 0o755, true);
        mkdir("{$root}/integrations/filament", 0o755, true);
        file_put_contents("{$root}/composer.json", '{"name": "offline-agency/image-puzzle", "version": "1.0.0"}');
        file_put_contents("{$root}/integrations/livewire/composer.json", '{"name": "offline-agency/image-puzzle-livewire"}');

        expect((new ArtifactBuilder($root))->composerProblems())->toBe([
            'composer.json declares a version: remove it, Packagist ignores tags that disagree with it',
            'integrations/filament/composer.json is missing or not valid JSON',
        ])
            ->and((new ArtifactBuilder(dirname(__DIR__, 2)))->composerProblems())->toBe([]);
    } finally {
        Fixtures::removeDir($root);
    }
});

it('reports an incomplete browser asset distribution', function (): void {
    $root = Fixtures::tempDir('ip-prebuilt-');
    try {
        mkdir("{$root}/packages/player/dist", 0o755, true);
        mkdir("{$root}/resources/dist", 0o755, true);
        mkdir("{$root}/integrations/livewire/resources/dist", 0o755, true);
        $builder = new ArtifactBuilder($root);

        expect($builder->prebuiltProblems())->toContain("{$root}/packages/player/dist/image-puzzle-player.iife.js is missing: build the player first");

        file_put_contents("{$root}/packages/player/dist/image-puzzle-player.iife.js", 'fresh iife');
        file_put_contents("{$root}/packages/player/dist/image-puzzle-player.bundle.js", 'fresh bundle');
        file_put_contents("{$root}/resources/dist/image-puzzle-player.iife.js", 'stale iife');

        expect($builder->prebuiltProblems())->toBe([
            'resources/dist/image-puzzle-player.iife.js differs from a fresh build: run npm run build && npm run sync:bundles and commit',
            'integrations/livewire/resources/dist/image-puzzle-player.bundle.js is missing',
        ]);

        file_put_contents("{$root}/resources/dist/image-puzzle-player.iife.js", 'fresh iife');
        file_put_contents("{$root}/integrations/livewire/resources/dist/image-puzzle-player.bundle.js", 'fresh bundle');

        expect($builder->prebuiltProblems())->toBe([]);
    } finally {
        Fixtures::removeDir($root);
    }
});
