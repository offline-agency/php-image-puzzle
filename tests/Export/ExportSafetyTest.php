<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\Tests\Support\Fixtures;
use ImagePuzzle\Tests\Support\ManifestAssertions;

/*
 * G10, G12, G13, G14: overwrite semantics, collisions, path confinement and
 * recoverable publication.
 */

beforeEach(function (): void {
    $this->dir = Fixtures::tempDir();
    $this->puzzle = (new PuzzleGenerator(new JigsawStyle()))->generateFromGd(Fixtures::coordinateImage(60, 40), 2, 3);
});

afterEach(function (): void {
    Fixtures::removeDir($this->dir);
});

test('duplicate destinations are rejected with no writes', function (string $pattern): void {
    $out = $this->dir . '/out';
    expect(fn () => $this->puzzle->export($out, (new ExportOptions())->withFilenamePattern($pattern)))
        ->toThrow(ExportFailedException::class);
    expect(file_exists($out))->toBeFalse();
})->with(['same.png', 'piece_{row}.png', 'p_{col}.png']);

test('case-insensitive duplicates and manifest collisions are rejected', function (): void {
    $out = $this->dir . '/out';
    $puzzle = (new PuzzleGenerator())->generateFromGd(imagecreatetruecolor(10, 10), 1, 1);
    expect(fn () => $puzzle->export($out, new ExportOptions(filenamePattern: 'manifest.png', manifestFilename: 'MANIFEST.png.json')))
        ->not->toThrow(ExportFailedException::class);
    expect(fn () => $puzzle->export($this->dir . '/b', new ExportOptions(filenamePattern: 'Piece.png', manifestFilename: 'piece.png')))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, '.json');
    expect(fn () => $puzzle->export($this->dir . '/c', new ExportOptions(filenamePattern: '.image-puzzle-x.png')))
        ->toThrow(ExportFailedException::class, 'reserved');
    expect(fn () => $puzzle->export($this->dir . '/d', new ExportOptions(filenamePattern: 'piece.gif')))
        ->toThrow(ExportFailedException::class, 'does not match');
    expect(file_exists($this->dir . '/c') ? ManifestAssertions::snapshot($this->dir . '/c') : [])->toBe([]);
});

test('NUL and control characters in names are rejected', function (): void {
    expect(fn () => new ExportOptions(filenamePattern: "a\0b.png"))->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class);
    expect(fn () => new ExportOptions(manifestFilename: "m\n.json"))->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class);
});

test('outside-base targets are rejected before any directory is created', function (string $relative): void {
    $base = $this->dir . '/base';
    mkdir($base);
    $sentinelDir = $this->dir . '/outside';
    mkdir($sentinelDir);
    file_put_contents($sentinelDir . '/sentinel.txt', 'keep');
    $before = ManifestAssertions::snapshot($this->dir);

    expect(fn () => $this->puzzle->export($base . '/' . $relative, new ExportOptions(), $base))
        ->toThrow(ExportFailedException::class);
    expect(ManifestAssertions::snapshot($this->dir))->toBe($before);
    expect(is_dir($this->dir . '/new-outside'))->toBeFalse();
})->with([
    'parent traversal' => ['../new-outside'],
    'nested traversal' => ['a/../../new-outside'],
    'prefix lookalike' => ['../base-evil'],
    'existing outside' => ['../outside'],
]);

test('symlinked directories pointing outside the base are rejected', function (): void {
    $base = $this->dir . '/base';
    mkdir($base);
    mkdir($this->dir . '/outside');
    file_put_contents($this->dir . '/outside/sentinel.txt', 'keep');
    symlink($this->dir . '/outside', $base . '/link');
    $before = ManifestAssertions::snapshot($this->dir . '/outside');

    expect(fn () => $this->puzzle->export($base . '/link', new ExportOptions(), $base))->toThrow(ExportFailedException::class, 'inside base');
    expect(fn () => $this->puzzle->export($base . '/link/deeper', new ExportOptions(), $base))->toThrow(ExportFailedException::class, 'inside base');
    expect(ManifestAssertions::snapshot($this->dir . '/outside'))->toBe($before);
    expect(is_dir($this->dir . '/outside/deeper'))->toBeFalse();
})->skip(PHP_OS_FAMILY === 'Windows', 'symlink semantics differ on Windows');

test('nested paths inside the base are created', function (): void {
    $base = $this->dir . '/base';
    mkdir($base);
    $result = $this->puzzle->export($base . '/a/b/c', new ExportOptions(), $base);
    ManifestAssertions::assertConsistent($result->getManifestPath());
    expect($result->getOutputDirectory())->toBe($base . '/a/b/c');
});

test('pre-existing destination symlinks are rejected, never followed', function (): void {
    $out = $this->dir . '/out';
    mkdir($out);
    file_put_contents($this->dir . '/victim.png', 'victim');
    symlink($this->dir . '/victim.png', $out . '/piece_0_0.png');
    expect(fn () => $this->puzzle->export($out, new ExportOptions()))->toThrow(ExportFailedException::class, 'symbolic link');
    expect(file_get_contents($this->dir . '/victim.png'))->toBe('victim');
})->skip(PHP_OS_FAMILY === 'Windows', 'symlink semantics differ on Windows');

test('overwrite=false keeps valid existing pieces and rewrites the manifest', function (): void {
    $out = $this->dir . '/out';
    $first = $this->puzzle->export($out, new ExportOptions());
    $piece = $out . '/piece_0_1.png';
    $size = getimagesize($piece);
    $sentinel = imagecreatetruecolor($size[0], $size[1]);
    imagefill($sentinel, 0, 0, imagecolorallocate($sentinel, 9, 9, 9));
    imagepng($sentinel, $piece);
    $sentinelHash = sha1_file($piece);
    unlink($first->getManifestPath());

    $result = $this->puzzle->export($out, new ExportOptions(overwrite: false));
    expect(sha1_file($piece))->toBe($sentinelHash);
    expect($result->getReusedFiles())->toHaveCount(6);
    ManifestAssertions::assertConsistent($result->getManifestPath());
});

test('overwrite=false rejects stale files with other dimensions before writing', function (): void {
    $out = $this->dir . '/out';
    mkdir($out);
    imagepng(imagecreatetruecolor(3, 3), $out . '/piece_1_2.png');
    $before = ManifestAssertions::snapshot($out);
    expect(fn () => $this->puzzle->export($out, new ExportOptions(overwrite: false)))->toThrow(ExportFailedException::class, 'Existing output cannot be used');
    expect(ManifestAssertions::snapshot($out))->toBe($before);
});

test('a failure while staging leaves the previous export untouched', function (): void {
    $out = $this->dir . '/out';
    $this->puzzle->export($out, new ExportOptions());
    $before = ManifestAssertions::snapshot($out);
    $count = 0;
    $other = (new PuzzleGenerator(new JigsawStyle()))->generateFromGd(Fixtures::coordinateImage(90, 60), 2, 3);
    expect(fn () => $other->export($out, new ExportOptions(onPieceBeforeSave: static function () use (&$count): void {
        if (++$count === 4) {
            throw new RuntimeException('injected encode failure');
        }
    })))->toThrow(RuntimeException::class, 'injected');
    expect(ManifestAssertions::snapshot($out))->toBe($before);
    ManifestAssertions::assertConsistent($out . '/manifest.json');
});

test('a manifest failure leaves the previous export untouched', function (): void {
    $out = $this->dir . '/out';
    $this->puzzle->export($out, new ExportOptions());
    $before = ManifestAssertions::snapshot($out);
    $other = (new PuzzleGenerator())->generateFromGd(Fixtures::coordinateImage(90, 60), 2, 3);
    expect(fn () => $other->export($out, new ExportOptions(onManifestBuild: static fn (array $m): array => ['extensions' => ['x' => NAN]] + $m)))
        ->toThrow(ExportFailedException::class);
    expect(ManifestAssertions::snapshot($out))->toBe($before);
});

test('a publication failure leaves no manifest (documented incomplete state) and a re-run recovers', function (): void {
    $out = $this->dir . '/out';
    $this->puzzle->export($out, new ExportOptions());
    // Pieces are published in row/col order; a callback after the first publish
    // turns the next destination into a directory so its rename fails.
    $options = new ExportOptions(onPieceAfterSave: static function ($piece, string $path) use ($out): void {
        if ($piece->getId() === 'r0c0') {
            unlink($out . '/piece_0_1.png');
            mkdir($out . '/piece_0_1.png');
        }
    });
    expect(fn () => $this->puzzle->export($out, $options))->toThrow(ExportFailedException::class);
    expect(file_exists($out . '/manifest.json'))->toBeFalse();
    expect(glob($out . '/.image-puzzle-*') ?: [])->toBe([]);

    rmdir($out . '/piece_0_1.png');
    $result = $this->puzzle->export($out, new ExportOptions());
    ManifestAssertions::assertConsistent($result->getManifestPath());
});

test('generation mode publishes an all-or-nothing view through current.json', function (): void {
    $out = $this->dir . '/out';
    $options = new ExportOptions(publishMode: ExportOptions::PUBLISH_GENERATION);
    $first = $this->puzzle->export($out, $options);
    $pointer = json_decode((string) file_get_contents($out . '/current.json'), true);
    expect($pointer['generation'])->toBe($first->getGenerationId());
    ManifestAssertions::assertConsistent($out . '/' . $pointer['manifest']);

    $count = 0;
    expect(fn () => $this->puzzle->export($out, $options->withOnPieceBeforeSave(static function () use (&$count): void {
        if (++$count === 2) {
            throw new RuntimeException('boom');
        }
    })))->toThrow(RuntimeException::class);
    // The failed generation is removed; current.json still points to the first one.
    expect(json_decode((string) file_get_contents($out . '/current.json'), true))->toBe($pointer);
    expect(glob($out . '/generations/*') ?: [])->toHaveCount(1);

    sleep(1);
    $second = $this->puzzle->export($out, $options);
    expect(\ImagePuzzle\Export\ExportSession::currentGenerationDirectory(realpath($out)))->toEndWith((string) $second->getGenerationId());
    expect(\ImagePuzzle\Export\ExportSession::pruneGenerations(realpath($out), 1))->toBe([$first->getGenerationId()]);
});

test('JPEG export flattens transparency onto the configured background', function (): void {
    $out = $this->dir . '/out';
    $image = imagecreatetruecolor(20, 20);
    imagealphablending($image, false);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    $puzzle = (new PuzzleGenerator())->generateFromGd($image, 1, 1);
    $result = $puzzle->export($out, new ExportOptions(format: ExportOptions::FORMAT_JPEG, jpegBackground: [255, 0, 0]));
    $jpg = imagecreatefromjpeg($result->getFiles()[0]);
    $c = imagecolorat($jpg, 10, 10);
    expect(($c >> 16) & 0xFF)->toBeGreaterThan(240)->and(($c >> 8) & 0xFF)->toBeLessThan(15);
    expect((new ExportOptions())->withFormat(ExportOptions::FORMAT_JPEG)->getQuality())->toBe(85);
});

test('WebP export when the encoder is available', function (): void {
    $out = $this->dir . '/out';
    $result = $this->puzzle->export($out, new ExportOptions(format: ExportOptions::FORMAT_WEBP));
    expect($result->getFiles()[0])->toEndWith('.webp');
    ManifestAssertions::assertConsistent($result->getManifestPath());
})->skip(!(\ImagePuzzle\Export\ImageEncoder::capabilities()['webp']), 'GD built without WebP');
