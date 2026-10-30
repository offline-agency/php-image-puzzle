<?php

declare(strict_types=1);

use ImagePuzzle\Cache\AssetCache;
use ImagePuzzle\Cache\CachedBuilder;
use ImagePuzzle\Cache\CacheKey;
use ImagePuzzle\Exception\StorageException;
use ImagePuzzle\Export\PcntlProcessControl;
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Geometry\Bounds;
use ImagePuzzle\Support\Tree;
use ImagePuzzle\Tests\Support\Fixtures;

final class CacheWorkspaces
{
    /** @var list<string> */
    public static array $dirs = [];
}

function cacheDir(): string
{
    $dir = Fixtures::tempDir('ip-cache-');
    CacheWorkspaces::$dirs[] = $dir;

    return $dir;
}

function cacheKey(int $seed = 1, string $source = '', ?AssetOptions $assets = null): CacheKey
{
    return CacheKey::forBuild($source === '' ? str_repeat('a', 64) : $source, new BuildConfiguration(GameKind::Swap, 'easy', [], $seed), $assets ?? new AssetOptions());
}

/** A producer that writes two files and counts its calls. */
function cacheProducer(int &$calls): Closure
{
    return static function (string $dir) use (&$calls): void {
        $calls++;
        file_put_contents($dir . '/definition.json', '{"n":' . $calls . '}');
        mkdir($dir . '/pieces');
        file_put_contents($dir . '/pieces/a.png', 'png');
    };
}

afterEach(function (): void {
    foreach (CacheWorkspaces::$dirs as $dir) {
        Fixtures::removeDir($dir);
    }
    CacheWorkspaces::$dirs = [];
});

describe('keys', function (): void {
    it('change with the source, the configuration, the output options and the engine', function (): void {
        $base = cacheKey();
        expect(cacheKey()->digest)->toBe($base->digest)
            ->and(cacheKey(2)->digest)->not->toBe($base->digest)
            ->and(cacheKey(1, str_repeat('b', 64))->digest)->not->toBe($base->digest)
            ->and(cacheKey(1, '', new AssetOptions('webp', 80))->digest)->not->toBe($base->digest)
            ->and(CacheKey::forBuild(str_repeat('a', 64), new BuildConfiguration(GameKind::Swap, 'easy', [], 1, new Bounds(0, 0, 10, 10)))->digest)->not->toBe($base->digest);
        $material = $base->material;
        expect($material['versions'] ?? null)->toHaveKeys(['definition', 'presets', 'geometry', 'manifest', 'generator'])
            ->and($material['backend'] ?? null)->toHaveKeys(['php', 'gd']);
    });

    it('reject a malformed source digest', function (): void {
        expect(fn () => cacheKey(1, 'not-a-digest'))->toThrow(ImagePuzzle\Exception\InvalidArgumentException::class);
    });
});

describe('entries', function (): void {
    it('produce once, then serve validated hits', function (): void {
        $cache = new AssetCache(cacheDir());
        $calls = 0;
        $first = $cache->remember(cacheKey(), cacheProducer($calls));
        $second = $cache->remember(cacheKey(), cacheProducer($calls));
        expect($calls)->toBe(1)
            ->and($first->hit)->toBeFalse()
            ->and($second->hit)->toBeTrue()
            ->and($second->path)->toBe($first->path)
            ->and(Tree::files($second->path))->toBe(['definition.json', 'pieces/a.png'])
            ->and($cache->get(cacheKey(2)))->toBeNull();
    });

    it('drop a corrupt, extended or mislabelled entry and rebuild it', function (Closure $damage): void {
        $root = cacheDir();
        $cache = new AssetCache($root);
        $calls = 0;
        $entry = $cache->remember(cacheKey(), cacheProducer($calls));
        $damage($entry->path);
        expect($cache->get(cacheKey()))->toBeNull()
            ->and(is_dir(dirname($entry->path)))->toBeFalse();
        $cache->remember(cacheKey(), cacheProducer($calls));
        expect($calls)->toBe(2)->and($cache->get(cacheKey()))->not->toBeNull();
    })->with([
        'changed file' => static fn (string $payload) => file_put_contents($payload . '/pieces/a.png', 'PNG'),
        'missing file' => static fn (string $payload) => unlink($payload . '/definition.json'),
        'extra file' => static fn (string $payload) => file_put_contents($payload . '/extra.txt', 'x'),
        'entry manifest for another key' => static function (string $payload): void {
            $file = dirname($payload) . '/entry.json';
            file_put_contents($file, str_replace('"seed":1', '"seed":9', (string) file_get_contents($file)));
        },
        'unreadable entry manifest' => static fn (string $payload) => file_put_contents(dirname($payload) . '/entry.json', '{'),
    ]);

    it('cache nothing when the producer fails', function (): void {
        $root = cacheDir();
        $cache = new AssetCache($root);
        expect(fn () => $cache->remember(cacheKey(), static function (string $dir): void {
            file_put_contents($dir . '/half.json', '{');
            throw new RuntimeException('build failed');
        }))->toThrow(RuntimeException::class, 'build failed');
        expect($cache->get(cacheKey()))->toBeNull()
            ->and(glob($root . '/staging/*'))->toBe([]);
        expect(fn () => $cache->remember(cacheKey(), static function (string $dir): void {
        }))->toThrow(StorageException::class, 'no files');
    });

    it('serialise concurrent writers of one key', function (): void {
        $root = cacheDir();
        $log = $root . '/produced.log';
        $pids = [];
        for ($i = 0; $i < 3; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $cache = new AssetCache($root);
                $entry = $cache->remember(cacheKey(), static function (string $dir) use ($log): void {
                    file_put_contents($log, "x\n", FILE_APPEND | LOCK_EX);
                    usleep(200000);
                    file_put_contents($dir . '/definition.json', '{}');
                });
                exit(is_file($entry->path . '/definition.json') ? 0 : 1);
            }
            $pids[] = $pid;
        }
        $process = new PcntlProcessControl();
        foreach ($pids as $pid) {
            $waited = $process->wait($pid);
            expect($process->exitedNormally($waited['status']))->toBeTrue()
                ->and($process->exitCode($waited['status']))->toBe(0);
        }
        expect(file($log))->toHaveCount(1)
            ->and((new AssetCache($root))->get(cacheKey()))->not->toBeNull();
    })->skip(!extension_loaded('pcntl') || PHP_SAPI !== 'cli', 'pcntl and CLI required');

    it('invalidate entries on request', function (): void {
        $cache = new AssetCache(cacheDir());
        $calls = 0;
        $cache->remember(cacheKey(), cacheProducer($calls));
        expect($cache->invalidate(cacheKey()))->toBeTrue()
            ->and($cache->invalidate(cacheKey()))->toBeFalse()
            ->and($cache->get(cacheKey()))->toBeNull();
    });
});

describe('pruning', function (): void {
    it('removes the least recently used entries beyond the size bound', function (): void {
        $root = cacheDir();
        $cache = new AssetCache($root, 0);
        $calls = 0;
        foreach ([1, 2, 3] as $seed) {
            $entry = $cache->remember(cacheKey($seed), cacheProducer($calls));
            touch(dirname($entry->path) . '/entry.json', 1000 + $seed);
        }
        touch(dirname((string) $cache->get(cacheKey(1))) . '/entry.json', 5000);
        $size = Tree::bytes(dirname((string) $cache->get(cacheKey(1))));
        $result = $cache->prune(2 * $size);
        expect($result['removed'])->toBe(1)
            ->and($cache->get(cacheKey(2)))->toBeNull()
            ->and($cache->get(cacheKey(1)))->not->toBeNull()
            ->and($cache->get(cacheKey(3)))->not->toBeNull();
    });

    it('removes old entries and abandoned staging, and bounds itself after a write', function (): void {
        $root = cacheDir();
        $calls = 0;
        $cache = new AssetCache($root, 0);
        $old = $cache->remember(cacheKey(1), cacheProducer($calls));
        touch(dirname($old->path) . '/entry.json', time() - 7200);
        mkdir($root . '/staging/abandoned');
        touch($root . '/staging/abandoned', time() - 7200);
        expect($cache->prune(null, 3600)['removed'])->toBe(1)
            ->and(is_dir($root . '/staging/abandoned'))->toBeFalse();

        $bounded = new AssetCache($root, 1);
        $bounded->remember(cacheKey(1), cacheProducer($calls));
        $latest = $bounded->remember(cacheKey(2), cacheProducer($calls));
        // The bound keeps the entry just written even when it alone exceeds it.
        expect($bounded->get(cacheKey(1)))->toBeNull()
            ->and($bounded->get(cacheKey(2)))->toBe($latest->path);
    });
});

describe('cached builds', function (): void {
    it('build a puzzle once per source, configuration and options', function (): void {
        $dir = cacheDir();
        imagepng(Fixtures::coordinateImage(96, 72, false), $dir . '/photo.png');
        $builder = new CachedBuilder(new AssetCache($dir . '/cache'));
        $config = new BuildConfiguration(GameKind::Tangram, 'easy', [], 5);
        $first = $builder->build($dir . '/photo.png', $config);
        $second = $builder->build($dir . '/photo.png', $config);
        expect($first->hit)->toBeFalse()
            ->and($second->hit)->toBeTrue()
            ->and($second->puzzle->definition->id)->toBe($first->puzzle->definition->id);
        imagepng(Fixtures::coordinateImage(96, 72, true), $dir . '/photo.png');
        expect($builder->build($dir . '/photo.png', $config)->hit)->toBeFalse();
    });
});
