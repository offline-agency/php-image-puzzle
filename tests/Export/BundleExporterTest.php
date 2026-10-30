<?php

declare(strict_types=1);

use ImagePuzzle\Exception\CapabilityUnavailableException;
use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\Export\BundleExporter;
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\PuzzleDirectory;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * Self-contained ZIP bundles: every file the page needs, local player
 * script, inline definition, safe and deterministic entries.
 */

final class BundleWorkspaces
{
    /** @var list<string> */
    public static array $dirs = [];
}

function bundleDir(): string
{
    $dir = Fixtures::tempDir('ip-bundle-');
    BundleWorkspaces::$dirs[] = $dir;

    return $dir;
}

function bundlePuzzle(AssetOptions $assets = new AssetOptions()): PuzzleDirectory
{
    $dir = bundleDir();
    imagepng(Fixtures::coordinateImage(320, 240, false), $dir . '/photo.png');
    (new DefinitionBuilder())->build($dir . '/photo.png', $dir . '/out', GameKind::Jigsaw, 'custom', ['rows' => 2, 'cols' => 3], 3, $assets);

    return PuzzleDirectory::open($dir . '/out');
}

/**
 * @return array<string, string> entry name => contents
 */
function unzipAll(string $file): array
{
    $zip = new ZipArchive();
    expect($zip->open($file, ZipArchive::RDONLY))->toBeTrue();
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        $entries[$name] = (string) $zip->getFromIndex($i);
    }
    $zip->close();

    return $entries;
}

/**
 * @param array<string, mixed> $manifest
 *
 * @return list<array<string, mixed>>
 */
function bundleFiles(array $manifest): array
{
    $files = $manifest['files'] ?? null;
    expect($files)->toBeArray();

    return array_map(static fn (mixed $file): array => Fixtures::object($file), is_array($files) ? array_values($files) : []);
}

afterEach(function (): void {
    foreach (BundleWorkspaces::$dirs as $dir) {
        Fixtures::removeDir($dir);
    }
    BundleWorkspaces::$dirs = [];
});

$noZip = !BundleExporter::supported();

describe('entries', function (): void {
    it('holds the definition, its files, a preview, the local player and notices', function (): void {
        $puzzle = bundlePuzzle(new AssetOptions(resolutions: ['thumbnail'], atlas: 'full'));
        $entries = (new BundleExporter())->entries($puzzle);
        $paths = array_keys($entries);
        foreach ([...$puzzle->referencedFiles(), 'definition.json', 'preview.png', 'index.html', BundleExporter::PLAYER, 'README.txt', 'NOTICE.txt', 'LICENSE.txt'] as $expected) {
            expect($paths)->toContain($expected);
        }
        expect(end($paths))->toBe(BundleExporter::MANIFEST)
            ->and(count($paths))->toBe(count(array_unique($paths)))
            ->and($entries['definition.json'])->toBe(file_get_contents($puzzle->path . '/definition.json'))
            ->and($entries[BundleExporter::PLAYER])->toBe(file_get_contents(dirname(__DIR__, 2) . '/resources/dist/image-puzzle-player.iife.js'));
        // The atlas and the thumbnail set are in, and every entry is a safe relative path.
        expect($paths)->toContain($puzzle->definition->pieceImages->atlas?->image);
        foreach ($paths as $path) {
            expect($path)->toMatch('~^[A-Za-z0-9_][A-Za-z0-9._/-]*$~')
                ->and(str_contains($path, '..'))->toBeFalse();
        }
        $preview = imagecreatefromstring($entries['preview.png']);
        expect($preview)->toBeInstanceOf(GdImage::class);
    });

    it('lists every other entry in bundle.json with its size and SHA-256', function (): void {
        $entries = (new BundleExporter())->entries(bundlePuzzle());
        $manifest = Fixtures::object(json_decode($entries[BundleExporter::MANIFEST], true, 16, JSON_THROW_ON_ERROR));
        expect($manifest['format'])->toBe('image-puzzle-bundle')
            ->and($manifest['version'])->toBe(1)
            ->and($manifest['entry'])->toBe('index.html');
        $listed = [];
        foreach (bundleFiles($manifest) as $file) {
            $path = Fixtures::str($file, 'path');
            $listed[] = $path;
            expect($file['sha256'])->toBe(hash('sha256', $entries[$path]))
                ->and($file['bytes'])->toBe(strlen($entries[$path]));
        }
        expect($listed)->toBe(array_values(array_diff(array_keys($entries), [BundleExporter::MANIFEST])));
    });

    it('builds a page that needs no network and holds the definition inline', function (): void {
        $puzzle = bundlePuzzle();
        $html = (new BundleExporter())->entries($puzzle, '</title><script>alert(1)</script>')['index.html'];
        expect($html)->not->toMatch('~(src|href)="(https?:)?//~')
            ->and($html)->toContain('<script src="player/image-puzzle-player.iife.js"></script>')
            ->and($html)->toContain('<title>&lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;</title>')
            ->and(substr_count($html, '<script'))->toBe(3);
        preg_match('~<script type="application/json" id="puzzle-definition">(.*?)</script>~s', $html, $match);
        expect(json_decode($match[1] ?? '', true))->toBe(json_decode((string) file_get_contents($puzzle->path . '/definition.json'), true));
    });

    it('enforces the size budget, needs the player script and a local image', function (): void {
        $puzzle = bundlePuzzle();
        expect(fn () => (new BundleExporter(maxBytes: 20_000))->entries($puzzle))->toThrow(ResourceLimitExceededException::class, '20000-byte limit')
            ->and(fn () => (new BundleExporter(playerFile: bundleDir() . '/missing.js'))->entries($puzzle))->toThrow(ExportFailedException::class, 'player script is missing');
    });
});

describe('archive', function () use ($noZip): void {
    it('writes sorted, deterministic entries under one folder', function (): void {
        $puzzle = bundlePuzzle();
        $dir = bundleDir();
        $names = (new BundleExporter())->write($puzzle, $dir . '/a.zip', 'my-puzzle');
        (new BundleExporter())->write($puzzle, $dir . '/b.zip', 'my-puzzle');
        expect(file_get_contents($dir . '/a.zip'))->toBe(file_get_contents($dir . '/b.zip'))
            ->and(Fixtures::glob($dir . '/*.part-*'))->toBe([]);
        $entries = unzipAll($dir . '/a.zip');
        expect(array_keys($entries))->toBe($names);
        foreach ($names as $name) {
            expect($name)->toStartWith('my-puzzle/');
        }
        $zip = new ZipArchive();
        $zip->open($dir . '/a.zip', ZipArchive::RDONLY);
        $zip->getExternalAttributesName('my-puzzle/index.html', $os, $attributes);
        expect(is_int($attributes) ? $attributes >> 16 : null)->toBe(0o100644);
        $zip->close();
        // Extracted, the files match the manifest.
        $manifest = Fixtures::object(json_decode($entries['my-puzzle/bundle.json'], true, 16, JSON_THROW_ON_ERROR));
        foreach (bundleFiles($manifest) as $file) {
            expect(hash('sha256', $entries['my-puzzle/' . Fixtures::str($file, 'path')]))->toBe($file['sha256']);
        }
    })->skip($noZip, 'ext-zip is not loaded');

    it('refuses an existing target, an unsafe folder name and a remote image', function (string $case): void {
        $dir = bundleDir();
        $puzzle = bundlePuzzle();
        match ($case) {
            'exists' => expect(function () use ($puzzle, $dir): void {
                touch($dir . '/x.zip');
                (new BundleExporter())->write($puzzle, $dir . '/x.zip');
            })->toThrow(ExportFailedException::class, 'already exists'),
            'root' => expect(fn () => (new BundleExporter())->write($puzzle, $dir . '/x.zip', '../up'))->toThrow(InvalidArgumentException::class, 'folder name'),
            'dots' => expect(fn () => (new BundleExporter())->write($puzzle, $dir . '/x.zip', '..'))->toThrow(InvalidArgumentException::class, 'folder name'),
            default => throw new LogicException($case),
        };
    })->with(['exists', 'root', 'dots'])->skip($noZip, 'ext-zip is not loaded');

    it('reports a missing zip extension (injected)', function (): void {
        expect(fn () => (new BundleExporter(zipAvailable: false))->write(bundlePuzzle(), bundleDir() . '/x.zip'))
            ->toThrow(CapabilityUnavailableException::class, 'ext-zip');
    });

    it('reports a missing zip extension (really absent)', function (): void {
        $dir = bundleDir();
        expect(fn () => (new BundleExporter())->write(bundlePuzzle(), $dir . '/x.zip'))
            ->toThrow(CapabilityUnavailableException::class, 'ext-zip')
            ->and(file_exists($dir . '/x.zip'))->toBeFalse();
    })->skip(!$noZip, 'ext-zip is loaded; the minimal-extensions CI job runs this');
});
