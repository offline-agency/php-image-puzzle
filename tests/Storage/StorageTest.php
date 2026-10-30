<?php

declare(strict_types=1);

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\StorageException;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\PuzzleDirectory;
use ImagePuzzle\Storage\FilesystemTarget;
use ImagePuzzle\Storage\FlysystemTarget;
use ImagePuzzle\Storage\MemoryTarget;
use ImagePuzzle\Storage\OutputTarget;
use ImagePuzzle\Storage\Publisher;
use ImagePuzzle\Storage\StorageKey;
use ImagePuzzle\Storage\UrlResolver;
use ImagePuzzle\Support\Tree;
use ImagePuzzle\Tests\Support\Fixtures;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

final class StorageWorkspaces
{
    /** @var list<string> */
    public static array $dirs = [];
}

function storageDir(): string
{
    $dir = Fixtures::tempDir('ip-storage-');
    StorageWorkspaces::$dirs[] = $dir;

    return $dir;
}

/** A built jigsaw (several referenced files) in a temporary directory. */
function storagePuzzle(int $seed = 3): PuzzleDirectory
{
    $dir = storageDir();
    imagepng(Fixtures::coordinateImage(120, 90, false), $dir . '/photo.png');
    (new DefinitionBuilder())->build($dir . '/photo.png', $dir . '/out', GameKind::Jigsaw, 'custom', ['rows' => 2, 'cols' => 2], $seed);

    return PuzzleDirectory::open($dir . '/out');
}

/** Wraps a target and fails the first write of a key containing $failOn. */
final class FailingTarget implements OutputTarget
{
    public function __construct(private readonly OutputTarget $inner, private readonly string $failOn)
    {
    }

    public function write(string $key, string $contents): void
    {
        $this->tick($key);
        $this->inner->write($key, $contents);
    }

    public function writeStream(string $key, $stream): void
    {
        $this->tick($key);
        $this->inner->writeStream($key, $stream);
    }

    public function exists(string $key): bool
    {
        return $this->inner->exists($key);
    }

    public function read(string $key): string
    {
        return $this->inner->read($key);
    }

    public function delete(string $key): void
    {
        $this->inner->delete($key);
    }

    private function tick(string $key): void
    {
        if (str_contains($key, $this->failOn)) {
            throw new StorageException("Simulated failure writing {$key}.");
        }
    }
}

afterEach(function (): void {
    foreach (StorageWorkspaces::$dirs as $dir) {
        Fixtures::removeDir($dir);
    }
    StorageWorkspaces::$dirs = [];
});

describe('keys', function (): void {
    it('accepts relative segment paths', function (string $key): void {
        expect(StorageKey::assert($key))->toBe($key);
    })->with(['definition.json', 'generations/gd1-ab-12/pieces/piece_0_1.png', 'a/b-c_d.e']);

    it('rejects keys that could leave the root', function (string $key): void {
        expect(fn () => StorageKey::assert($key))->toThrow(InvalidArgumentException::class, 'Invalid storage key');
    })->with(['', '../x', 'a/../b', '/abs', 'a//b', '.hidden', 'a/.git/config', 'a\\b', 'a/', 'sp ace', "nul\0", str_repeat('a', 513)]);
});

describe('targets', function (): void {
    $targets = [
        'filesystem' => fn (): OutputTarget => new FilesystemTarget(storageDir()),
        'memory' => fn (): OutputTarget => new MemoryTarget(),
        'flysystem (in-memory adapter)' => fn (): OutputTarget => new FlysystemTarget(new Filesystem(new InMemoryFilesystemAdapter())),
    ];

    it('writes, overwrites, streams, reads and deletes objects', function (Closure $make): void {
        $target = $make();
        assert($target instanceof OutputTarget);
        expect($target->exists('a/b.txt'))->toBeFalse();
        $target->write('a/b.txt', 'one');
        $target->write('a/b.txt', 'two');
        expect($target->read('a/b.txt'))->toBe('two');
        $stream = fopen('php://memory', 'w+b');
        assert($stream !== false);
        fwrite($stream, 'streamed');
        $target->writeStream('c.bin', $stream);
        fclose($stream);
        expect($target->read('c.bin'))->toBe('streamed');
        $target->delete('a/b.txt');
        $target->delete('a/b.txt');
        expect($target->exists('a/b.txt'))->toBeFalse()
            ->and(fn () => $target->read('a/b.txt'))->toThrow(StorageException::class);
        expect(fn () => $target->write('../escape', 'x'))->toThrow(InvalidArgumentException::class);
    })->with($targets);

    it('leaves no temporary files in a filesystem target', function (): void {
        $root = storageDir();
        $target = new FilesystemTarget($root);
        $target->write('x/y.json', '{}');
        $other = [];
        expect(Tree::files($root, $other))->toBe(['x/y.json'])->and($other)->toBe([]);
    });
});

describe('publisher', function (): void {
    it('publishes every file under an immutable generation, then the reference', function (): void {
        $puzzle = storagePuzzle();
        $target = new MemoryTarget();
        $publication = (new Publisher($target, 'site'))->publish($puzzle, 'cat');

        expect($publication->reused)->toBeFalse()
            ->and($publication->referenceKey)->toBe('site/puzzles/cat.json')
            ->and($publication->generation)->toStartWith('site/generations/' . $puzzle->definition->id . '-')
            ->and($publication->files)->toContain('definition.json', 'source.png')
            ->and(count($publication->files))->toBe(2 + 4);
        foreach ($publication->files as $file) {
            $local = $file === 'definition.json' ? $puzzle->path . '/definition.json' : $puzzle->file($file);
            expect($target->read($publication->generation . '/' . $file))->toBe(file_get_contents($local));
        }
        $reference = Fixtures::object(json_decode($target->read('site/puzzles/cat.json'), true));
        expect($reference['definition'])->toBe($publication->definitionKey)
            ->and($reference['definitionId'])->toBe($puzzle->definition->id);
        $resolved = (new Publisher($target, 'site'))->resolve('cat');
        expect($resolved->generation)->toBe($publication->generation)
            ->and($resolved->files)->toBe($publication->files);
    });

    it('reuses an identical generation and keeps generations of other content apart', function (): void {
        $target = new MemoryTarget();
        $publisher = new Publisher($target);
        $first = $publisher->publish(storagePuzzle(3), 'cat');
        $keys = $target->keys();
        $again = $publisher->publish(storagePuzzle(3), 'cat');
        expect($again->reused)->toBeTrue()
            ->and($again->generation)->toBe($first->generation)
            ->and($target->keys())->toBe($keys);
        $other = $publisher->publish(storagePuzzle(4), 'cat');
        expect($other->generation)->not->toBe($first->generation)
            ->and($target->exists($first->generation . '/definition.json'))->toBeTrue()
            ->and($publisher->resolve('cat')->generation)->toBe($other->generation);
    });

    it('never moves the reference when an upload fails part-way', function (string $failOn): void {
        $memory = new MemoryTarget();
        (new Publisher($memory))->publish(storagePuzzle(3), 'cat');
        $before = $memory->read('puzzles/cat.json');
        $failing = new FailingTarget($memory, $failOn);
        expect(fn () => (new Publisher($failing))->publish(storagePuzzle(4), 'cat'))->toThrow(StorageException::class, 'Simulated failure');
        expect($memory->read('puzzles/cat.json'))->toBe($before);
    })->with(['a piece image' => 'pieces/', 'the definition' => 'definition.json', 'the generation manifest' => 'generation.json', 'the reference' => 'puzzles/']);

    it('repairs an incomplete or damaged generation before referencing it', function (): void {
        $target = new MemoryTarget();
        $publication = (new Publisher($target))->publish(storagePuzzle(), 'cat');
        $target->write($publication->generation . '/generation.json', '{"tampered":true}');
        $target->delete($publication->generation . '/source.png');
        $again = (new Publisher($target))->publish(storagePuzzle(), 'dog');
        expect($again->reused)->toBeFalse()
            ->and($again->generation)->toBe($publication->generation)
            ->and($target->exists($publication->generation . '/source.png'))->toBeTrue()
            ->and((new Publisher($target))->resolve('dog')->files)->toBe($publication->files);
    });

    it('validates names and prefixes', function (): void {
        expect(fn () => (new Publisher(new MemoryTarget()))->publish(storagePuzzle(), '../cat'))->toThrow(InvalidArgumentException::class, 'Publication name')
            ->and(fn () => new Publisher(new MemoryTarget(), '/abs'))->toThrow(InvalidArgumentException::class);
    });

    it('publishes through Flysystem and wraps its errors', function (): void {
        $target = new FlysystemTarget(new Filesystem(new InMemoryFilesystemAdapter()));
        $publication = (new Publisher($target))->publish(storagePuzzle(), 'cat');
        expect($target->read($publication->definitionKey))->toContain('image-puzzle/game-definition')
            ->and(fn () => $target->read('missing.json'))->toThrow(StorageException::class, 'missing.json');
    });
});

describe('urls', function (): void {
    it('maps keys to consumer URLs without exposing anything else', function (): void {
        expect(UrlResolver::prefix('https://cdn.example.test/puzzles/')->url('generations/g-1/definition.json'))
            ->toBe('https://cdn.example.test/puzzles/generations/g-1/definition.json')
            ->and(UrlResolver::prefix('/media')->url('a/b.png'))->toBe('/media/a/b.png');
        $seen = [];
        $signed = UrlResolver::using(function (string $key) use (&$seen): string {
            $seen[] = $key;

            return '/play/' . $key . '?sig=abc';
        });
        expect($signed->url('g/definition.json'))->toBe('/play/g/definition.json?sig=abc')
            ->and($seen)->toBe(['g/definition.json'])
            ->and(fn () => $signed->url('../secret'))->toThrow(InvalidArgumentException::class);
        expect(fn () => UrlResolver::prefix('javascript:alert(1)'))->toThrow(InvalidArgumentException::class);
    });
});
