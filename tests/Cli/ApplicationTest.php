<?php

declare(strict_types=1);

use ImagePuzzle\Cli\Application;
use ImagePuzzle\Cli\Arguments;
use ImagePuzzle\Cli\UsageException;
use ImagePuzzle\Export\BundleExporter;
use ImagePuzzle\ResourceLimits;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * The image-puzzle command: commands, options, exit codes and messages.
 * Most cases run the Application in-process; the bin script itself is run
 * as a subprocess for its exit status and for a GD build without WebP.
 */

final class CliWorkspaces
{
    /** @var list<string> */
    public static array $dirs = [];
}

function cliDir(): string
{
    $dir = Fixtures::tempDir('ip-cli-');
    CliWorkspaces::$dirs[] = $dir;

    return $dir;
}

/** A 320x240 coordinate picture in a new directory. */
function cliImage(): string
{
    $dir = cliDir();
    imagepng(Fixtures::coordinateImage(320, 240, false), $dir . '/photo.png');

    return $dir . '/photo.png';
}

/**
 * @param list<string> $argv
 *
 * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
 */
function cli(array $argv, ResourceLimits $limits = new ResourceLimits()): array
{
    $out = fopen('php://memory', 'w+b');
    $err = fopen('php://memory', 'w+b');
    assert($out !== false && $err !== false);
    $code = (new Application($out, $err, $limits))->run($argv);
    rewind($out);
    rewind($err);

    return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}

/**
 * A command line from a dataset.
 *
 * @return list<string>
 */
function argvList(mixed $value): array
{
    $list = [];
    foreach (is_array($value) ? $value : [] as $item) {
        $list[] = is_string($item) ? $item : throw new LogicException('Command line arguments are strings.');
    }

    return $list;
}

/**
 * @return array<string, mixed>
 */
function cliJson(string $output): array
{
    return Fixtures::object(json_decode($output, true, 32, JSON_THROW_ON_ERROR));
}

/** A built jigsaw puzzle directory. */
function cliPuzzle(string ...$options): string
{
    $out = cliDir() . '/puzzle';
    [$code, , $err] = cli(['generate', cliImage(), $out, '--rows=2', '--cols=3', '--seed=4', ...array_values($options)]);
    expect($code)->toBe(0, $err);

    return $out;
}

afterEach(function (): void {
    foreach (CliWorkspaces::$dirs as $dir) {
        Fixtures::removeDir($dir);
    }
    CliWorkspaces::$dirs = [];
});

describe('Arguments', function (): void {
    it('parses long options, flags, repeated values and "--"', function (): void {
        $args = Arguments::parse(['a', '--x=1', '--y', '2', '--f', '--p=k=v', '--p', 'k2=v', '--', '--z'], ['x', 'y', 'p'], ['f'], ['p']);
        expect($args->positional)->toBe(['a', '--z'])
            ->and($args->value('x'))->toBe('1')
            ->and($args->int('y', 0, 5))->toBe(2)
            ->and($args->flag('f'))->toBeTrue()
            ->and($args->all('p'))->toBe(['k=v', 'k2=v']);
    });

    it('rejects malformed command lines', function (array $argv, string $message): void {
        expect(fn () => Arguments::parse(argvList($argv), ['x'], ['f'])->int('x', 0, 9))->toThrow(UsageException::class, $message);
    })->with([
        'unknown' => [['--nope'], 'Unknown option --nope'],
        'missing value' => [['--x'], '--x needs a value'],
        'flag value' => [['--f=1'], 'does not take a value'],
        'repeated' => [['--x=1', '--x=2'], 'more than once'],
        'range' => [['--x=10'], 'from 0 to 9'],
        'not a number' => [['--x=1e3'], 'from 0 to 9'],
    ]);
});

describe('help and usage', function (): void {
    it('lists commands and exit codes', function (): void {
        [$code, $out] = cli([]);
        expect($code)->toBe(0)
            ->and($out)->toContain('generate')->toContain('bundle')->toContain('Exit codes: 0 ok');
        [$code, $out] = cli(['help', 'pdf']);
        expect($code)->toBe(0)->and($out)->toContain('--no-cut-lines');
        [$code, $out] = cli(['svg', '--help']);
        expect($code)->toBe(0)->and($out)->toContain('--image=MODE');
        [$code, $out] = cli(['--version']);
        expect($code)->toBe(0)->and($out)->toStartWith('image-puzzle ');
    });

    it('exits with 2 on usage errors', function (array $argv, string $message): void {
        [$code, $out, $err] = cli(argvList($argv));
        expect($code)->toBe(Application::EXIT_USAGE)
            ->and($out)->toBe('')
            ->and($err)->toStartWith('image-puzzle: ')->toContain($message);
    })->with([
        'unknown command' => [['explode'], 'Unknown command "explode"'],
        'unknown option' => [['generate', 'a.png', 'out', '--colour=red'], 'Unknown option --colour'],
        'missing argument' => [['generate', 'a.png'], 'Usage: image-puzzle generate'],
        'bad choice' => [['generate', 'a.png', 'out', '--format=bmp'], '--format must be one of: png, jpeg, webp'],
        'bad kind' => [['generate', 'a.png', 'out', '--kind=chess'], '--kind must be one of'],
        'bad param' => [['generate', 'a.png', 'out', '--param=rows'], '--param must be KEY=VALUE'],
        'config conflict' => [['generate', 'a.png', 'out', '--config=c.json', '--seed=2'], 'cannot be combined with --config'],
        'help topic' => [['help', 'nope'], 'Unknown command "nope"'],
    ]);
});

describe('generate', function (): void {
    it('builds a puzzle and reports it', function (): void {
        $out = cliDir() . '/p';
        [$code, $json] = cli(['generate', cliImage(), $out, '--rows=2', '--cols=3', '--format=webp', '--resolutions=small:160', '--atlas=full', '--json']);
        expect($code)->toBe(0);
        $result = cliJson($json);
        expect($result['kind'])->toBe('jigsaw')
            ->and($result['tiles'])->toBe(6)
            ->and(is_file($out . '/definition.json'))->toBeTrue()
            ->and(is_file($out . '/atlas.webp'))->toBeTrue()
            ->and(Fixtures::glob($out . '/pieces-small/*.webp'))->toHaveCount(6);
    });

    it('builds grid modes with presets and JSON parameters', function (): void {
        $out = cliDir() . '/p';
        [$code, $text, $err] = cli(['generate', cliImage(), $out, '--kind=rotation', '--rows=2', '--cols=2', '--param=orientations=[0,90,180,270]', '--seed=9']);
        expect($code)->toBe(0, $err)->and($text)->toContain('Built rotation puzzle gd1-');
        $definition = cliJson((string) file_get_contents($out . '/definition.json'));
        expect($definition['kind'])->toBe('rotation')->and($definition['seed'])->toBe(9);
    });

    it('uses a versioned build configuration', function (): void {
        $dir = cliDir();
        file_put_contents($dir . '/build.json', json_encode(['format' => 'image-puzzle/build-config', 'version' => 1, 'kind' => 'swap', 'preset' => 'easy', 'seed' => 3, 'crop' => ['x' => 10, 'y' => 0, 'width' => 300, 'height' => 240]], JSON_THROW_ON_ERROR));
        [$code, $json, $err] = cli(['generate', cliImage(), $dir . '/p', '--config=' . $dir . '/build.json', '--json']);
        expect($code)->toBe(0, $err)->and(cliJson($json)['kind'])->toBe('swap');
        $definition = cliJson((string) file_get_contents($dir . '/p/definition.json'));
        expect(Fixtures::object($definition['source'])['width'])->toBe(300);
    });

    it('plans without writing on --dry-run', function (): void {
        $out = cliDir() . '/never';
        [$code, $json] = cli(['generate', cliImage(), $out, '--rows=3', '--cols=4', '--format=webp', '--dry-run', '--json']);
        expect($code)->toBe(0)->and(file_exists($out))->toBeFalse();
        $plan = cliJson($json);
        expect($plan['dryRun'])->toBeTrue()
            ->and($plan['pieces'])->toBe(12)
            ->and($plan['board'])->toBe(['width' => 320, 'height' => 240])
            ->and($plan['estimatedMemoryBytes'])->toBe(320 * 240 * ResourceLimits::BYTES_PER_PIXEL_ESTIMATE)
            ->and(Fixtures::object($plan['source'])['type'])->toBe('png');
        [$code, $text] = cli(['generate', cliImage(), $out, '--dry-run']);
        expect($code)->toBe(0)->and($text)->toContain('Dry run: nothing was written.')->and(file_exists($out))->toBeFalse();
    });

    it('exits with 3 on invalid input', function (callable $setup, string $message): void {
        $dir = cliDir();
        [$code, , $err] = cli(argvList($setup($dir)));
        expect($code)->toBe(Application::EXIT_INVALID)->and($err)->toContain($message);
    })->with([
        'missing image' => [fn (string $d): array => ['generate', "{$d}/none.png", "{$d}/o"], 'none.png'],
        'not an image' => [function (string $d): array {
            file_put_contents("{$d}/fake.png", 'not a png');

            return ['generate', "{$d}/fake.png", "{$d}/o"];
        }, 'fake.png'],
        'non-empty output' => [function (string $d): array {
            mkdir("{$d}/o");
            touch("{$d}/o/keep");

            return ['generate', cliImage(), "{$d}/o", '--dry-run'];
        }, 'must be empty'],
        'malformed config' => [function (string $d): array {
            file_put_contents("{$d}/c.json", '{"format": ');

            return ['generate', cliImage(), "{$d}/o", "--config={$d}/c.json"];
        }, ''],
        'invalid config' => [function (string $d): array {
            file_put_contents("{$d}/c.json", '{"format":"image-puzzle/build-config","version":1,"kind":"jigsaw","preset":"impossible","seed":1}');

            return ['generate', cliImage(), "{$d}/o", "--config={$d}/c.json"];
        }, 'preset'],
        'unknown custom key' => [fn (string $d): array => ['generate', cliImage(), "{$d}/o", '--param=colour=red'], 'colour'],
        'atlas for grid mode' => [fn (string $d): array => ['generate', cliImage(), "{$d}/o", '--kind=swap', '--atlas=full', '--dry-run'], 'only available for jigsaw'],
        'unknown resolution' => [fn (string $d): array => ['generate', cliImage(), "{$d}/o", '--resolutions=huge'], 'Unknown resolution "huge"'],
    ]);

    it('exits with 5 when a resource limit is exceeded', function (): void {
        $dir = cliDir();
        [$code, , $err] = cli(['generate', cliImage(), $dir . '/o', '--rows=10', '--cols=10', '--dry-run'], new ResourceLimits(maxPieces: 50));
        expect($code)->toBe(Application::EXIT_LIMIT)->and($err)->toContain('50');
        [$code] = cli(['generate', cliImage(), $dir . '/o'], new ResourceLimits(maxPixels: 1000));
        expect($code)->toBe(Application::EXIT_LIMIT)->and(file_exists($dir . '/o'))->toBeFalse();
        file_put_contents($dir . '/big.json', str_repeat(' ', 20000));
        [$code, , $err] = cli(['generate', cliImage(), $dir . '/o', '--config=' . $dir . '/big.json']);
        expect($code)->toBe(Application::EXIT_LIMIT)->and($err)->toContain('limit is 16384');
    });
});

describe('puzzle commands', function (): void {
    it('validates definitions', function (): void {
        $puzzle = cliPuzzle();
        [$code, $out] = cli(['validate', $puzzle]);
        expect($code)->toBe(0)->and($out)->toContain('Valid jigsaw definition gd1-');
        [$code, $json] = cli(['validate', $puzzle . '/definition.json', '--json']);
        expect($code)->toBe(0)->and(cliJson($json)['valid'])->toBeTrue();
        file_put_contents($puzzle . '/definition.json', str_replace('"seed": 4', '"seed": 5', (string) file_get_contents($puzzle . '/definition.json')));
        [$code, , $err] = cli(['validate', $puzzle]);
        expect($code)->toBe(Application::EXIT_INVALID)->and($err)->toContain('does not match its content');
    });

    it('inspects a puzzle and its files', function (): void {
        $puzzle = cliPuzzle('--resolutions=small:160');
        [$code, $text] = cli(['inspect', $puzzle]);
        expect($code)->toBe(0)
            ->and($text)->toContain('Kind:    jigsaw (preset custom, seed 4)')
            ->and($text)->toContain('Set:     small (160 px wide)')
            ->and($text)->toContain('all present');
        [$code, $json] = cli(['inspect', $puzzle, '--json']);
        $info = cliJson($json);
        expect($code)->toBe(0)->and($info['tiles'])->toBe(6)->and($info['files'])->toHaveCount(13);
        unlink(Fixtures::glob($puzzle . '/pieces/*.png')[0]);
        [$code, , $err] = cli(['inspect', $puzzle]);
        expect($code)->toBe(Application::EXIT_INVALID)->and($err)->toContain('Missing file referenced by the definition');
    });

    it('reconstructs the solved board', function (string $kind): void {
        $dir = cliDir();
        $source = cliImage();
        $options = $kind === 'jigsaw' ? ['--rows=2', '--cols=3'] : ['--kind=' . $kind, '--preset=easy'];
        expect(cli(['generate', $source, $dir . '/p', ...$options])[0])->toBe(0);
        [$code] = cli(['reconstruct', $dir . '/p', $dir . '/board.png']);
        expect($code)->toBe(0);
        $board = Fixtures::load($dir . '/board.png');
        $expected = Fixtures::load($source);
        $crop = ImagePuzzle\Game\PuzzleDirectory::open($dir . '/p')->definition->crop;
        expect([imagesx($board), imagesy($board)])->toBe([$crop->width, $crop->height]);
        $off = 0;
        for ($y = 1; $y < $crop->height; $y += 7) {
            for ($x = 1; $x < $crop->width; $x += 7) {
                $off += Fixtures::pixel($board, $x, $y) === Fixtures::pixel($expected, $crop->x + $x, $crop->y + $y) ? 0 : 1;
            }
        }
        // Only antialiased jigsaw seams may differ; grid modes are exact.
        expect($off)->toBeLessThanOrEqual($kind === 'jigsaw' ? 40 : 0);
        [$code, , $err] = cli(['reconstruct', $dir . '/p', $dir . '/board.png']);
        expect($code)->toBe(Application::EXIT_FAILURE)->and($err)->toContain('must not exist');
    })->with(['jigsaw', 'sliding']);

    it('exports SVG, PDF and bundles', function (): void {
        $puzzle = cliPuzzle();
        $dir = dirname($puzzle);
        [$code] = cli(['svg', $puzzle, $dir . '/p.svg', '--image=embed', '--width-mm=150', '--stroke=#ff0000', '--title=Mine']);
        expect($code)->toBe(0)
            ->and((string) file_get_contents($dir . '/p.svg'))->toContain('width="150mm"')->toContain('data:image/png;base64,')->toContain('<title>Mine</title>');
        [$code, $json] = cli(['pdf', $puzzle, $dir . '/p.pdf', '--paper=a3', '--width-mm=500', '--json']);
        $layout = cliJson($json);
        expect($code)->toBe(0)
            ->and($layout['pages'])->toBe(3)
            ->and($layout['sheets'])->toBe([2, 1])
            ->and((string) file_get_contents($dir . '/p.pdf'))->toStartWith('%PDF-1.4');
        [$code, $text] = cli(['pdf', $puzzle, $dir . '/never.pdf', '--dry-run']);
        expect($code)->toBe(0)->and($text)->toContain('Would write')->and(file_exists($dir . '/never.pdf'))->toBeFalse();
        [$code, $text] = cli(['bundle', $puzzle, $dir . '/never.zip', '--dry-run']);
        expect($code)->toBe(0)->and($text)->toContain('image-puzzle/index.html')->and(file_exists($dir . '/never.zip'))->toBeFalse();
        if (BundleExporter::supported()) {
            [$code, $text] = cli(['bundle', $puzzle, $dir . '/p.zip', '--folder=demo']);
            expect($code)->toBe(0)->and($text)->toContain('open demo/index.html')->and(is_file($dir . '/p.zip'))->toBeTrue();
        }
    });

    it('maps option values the exporters reject to exit code 3', function (array $argv, string $message): void {
        $puzzle = cliPuzzle();
        $argv = argvList($argv);
        [$code, , $err] = cli([$argv[0], $puzzle, dirname($puzzle) . '/out', ...array_slice($argv, 1)]);
        expect($code)->toBe(Application::EXIT_INVALID)->and($err)->toContain($message);
    })->with([
        'stroke' => [['svg', '--stroke=red'], 'stroke'],
        'margin' => [['pdf', '--margin-mm=2'], 'margin'],
        'folder' => [['bundle', '--folder=../x'], 'folder name'],
    ]);

    it('reports capabilities', function (): void {
        [$code, $json] = cli(['capabilities', '--json']);
        $info = cliJson($json);
        expect($code)->toBe(0)
            ->and($info['gd'])->toBeTrue()
            ->and(Fixtures::object($info['encode'])['png'])->toBeTrue()
            ->and($info['zip'])->toBe(BundleExporter::supported());
    });
});

describe('bin/image-puzzle', function (): void {
    /**
     * @param list<string> $args
     * @param list<string> $ini
     *
     * @return array{0: int, 1: string, 2: string}
     */
    function runBin(array $args, array $ini = []): array
    {
        $command = [PHP_BINARY];
        foreach ($ini as $setting) {
            array_push($command, '-d', $setting);
        }
        $process = proc_open([...$command, dirname(__DIR__, 2) . '/bin/image-puzzle', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assert(is_resource($process));
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    it('returns the exit codes as process status', function (): void {
        expect(runBin(['help'])[0])->toBe(0)
            ->and(runBin(['generate'])[0])->toBe(2)
            ->and(runBin(['validate', cliDir()])[0])->toBe(3);
    });

    it('exits with 4 when GD cannot encode the requested format', function (): void {
        [$code, , $err] = runBin(['generate', cliImage(), cliDir() . '/o', '--format=webp'], ['disable_functions=imagewebp']);
        expect($code)->toBe(Application::EXIT_CAPABILITY)->and($err)->toContain('cannot encode webp');
    });
});
