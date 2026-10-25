<?php

declare(strict_types=1);

use ImagePuzzle\ExportOptions;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\Tests\Support\Fixtures;

function getTempDir(): string
{
    return sys_get_temp_dir() . '/image-puzzle-export-' . uniqid();
}

test('export with directory trailing slash normalizes path', function (): void {
    $outDir = getTempDir() . DIRECTORY_SEPARATOR;
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $result = $puzzle->export($outDir, new ExportOptions());
    expect($result->getOutputDirectory())->not->toEndWith(DIRECTORY_SEPARATOR);
    expect(rtrim($result->getOutputDirectory(), '/\\'))->toBe(rtrim($outDir, '/\\'));
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir(rtrim($outDir, '/\\'));
});

test('export creates directory and writes files', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $options = new ExportOptions();
    $result = $puzzle->export($outDir, $options);

    expect($result->getOutputDirectory())->toBe($outDir);
    $files = $result->getFiles();
    expect($files)->toHaveCount(5);
    foreach ($files as $path) {
        expect($path)->toBeFile();
    }
    $manifestPath = $result->getManifestPath();
    expect($manifestPath)->toBeFile();
    $manifest = $result->getManifest();
    expect($manifest)->toHaveKeys(['source', 'rows', 'cols', 'sourceWidth', 'sourceHeight', 'pieces']);
    expect($manifest['rows'])->toBe(2);
    expect($manifest['cols'])->toBe(2);
    expect($manifest['pieces'])->toHaveCount(4);
    foreach (Fixtures::pieces($manifest) as $p) {
        expect($p)->toHaveKeys(['row', 'col', 'x', 'y', 'width', 'height', 'filename']);
    }

    $decoded = json_decode((string) file_get_contents($manifestPath), true);
    expect($decoded)->toBeArray();
    expect($decoded)->toBe($manifest);

    array_map('unlink', $files);
    rmdir($outDir);
});

test('export manifest contains correct piece data', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $result = $puzzle->export($outDir, new ExportOptions());
    $manifest = $result->getManifest();
    $pieces = Fixtures::pieces($manifest);
    $piece00 = null;
    foreach ($pieces as $p) {
        if ($p['row'] === 0 && $p['col'] === 0) {
            $piece00 = $p;
            break;
        }
    }
    expect($piece00)->not->toBeNull();
    if ($piece00 === null) {
        return;
    }
    expect($piece00['x'])->toBe(0);
    expect($piece00['y'])->toBe(0);
    expect($piece00['width'])->toBe(16);
    expect($piece00['height'])->toBe(16);
    expect($piece00['filename'])->toContain('piece_0_0');

    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export with JPEG format writes jpg files', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $options = new ExportOptions(format: ExportOptions::FORMAT_JPEG, quality: 90);
    $result = $puzzle->export($outDir, $options);

    $files = $result->getFiles();
    expect($files)->toHaveCount(5);
    $pieceFiles = array_filter($files, fn (string $p) => str_contains($p, 'piece_'));
    foreach ($pieceFiles as $path) {
        expect($path)->toEndWith('.jpg');
        expect($path)->toBeFile();
    }
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export with custom manifest filename', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $options = new ExportOptions(manifestFilename: 'puzzle-manifest.json');
    $result = $puzzle->export($outDir, $options);

    expect($result->getManifestPath())->toEndWith('puzzle-manifest.json');
    expect($result->getManifestPath())->toBeFile();
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export with custom filename pattern', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $options = new ExportOptions(filenamePattern: 'tile_{row}x{col}.png');
    $result = $puzzle->export($outDir, $options);

    $piecePath = $outDir . DIRECTORY_SEPARATOR . 'tile_0x0.png';
    expect($piecePath)->toBeFile();
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export to non-existent directory creates it', function (): void {
    $outDir = getTempDir();
    expect(is_dir($outDir))->toBeFalse();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $result = $puzzle->export($outDir, new ExportOptions());
    expect(is_dir($outDir))->toBeTrue();
    expect($result->getOutputDirectory())->toBe($outDir);
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export to non-writable directory throws', function (): void {
    $outDir = getTempDir();
    mkdir($outDir, 0555);
    if (!is_writable($outDir)) {
        $generator = new PuzzleGenerator();
        $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
        expect(fn () => $puzzle->export($outDir, new ExportOptions()))
            ->toThrow(\ImagePuzzle\Exception\ExportFailedException::class, 'not writable');
    }
    @chmod($outDir, 0755);
    @rmdir($outDir);
})->skip(!function_exists('chmod') || PHP_OS_FAMILY === 'Windows', 'chmod / writability test not reliable on this platform');

test('export when output directory cannot be created throws Failed to create directory', function (): void {
    $baseDir = getTempDir();
    mkdir($baseDir, 0755);
    $outDir = $baseDir . DIRECTORY_SEPARATOR . 'subdir';
    chmod($baseDir, 0555);
    set_error_handler(static function (): bool {
        return true;
    }, E_WARNING | E_NOTICE);
    try {
        $generator = new PuzzleGenerator();
        $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
        expect(fn () => $puzzle->export($outDir, new ExportOptions()))
            ->toThrow(\ImagePuzzle\Exception\ExportFailedException::class, 'Failed to create directory');
    } finally {
        restore_error_handler();
        @chmod($baseDir, 0755);
        @rmdir($baseDir);
    }
})->skip(!function_exists('chmod') || PHP_OS_FAMILY === 'Windows', 'chmod not reliable on this platform');

test('export with overwrite false does not overwrite existing piece files', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $options = new ExportOptions(overwrite: true);
    $puzzle->export($outDir, $options);

    $piecePath = $outDir . DIRECTORY_SEPARATOR . 'piece_0_0.png';
    expect($piecePath)->toBeFile();

    // A valid image with the expected dimensions but different content is kept.
    $sentinel = Fixtures::canvas(32, 32);
    imagefill($sentinel, 0, 0, Fixtures::color($sentinel, 1, 2, 3));
    imagepng($sentinel, $piecePath);
    $sentinelBytes = file_get_contents($piecePath);
    $optionsNoOverwrite = new ExportOptions(overwrite: false);
    $result = $puzzle->export($outDir, $optionsNoOverwrite);

    expect($result->getFiles())->toContain($piecePath);
    expect($result->getReusedFiles())->toBe([$piecePath]);
    expect(file_get_contents($piecePath))->toBe($sentinelBytes);

    // A file that is not a matching image is rejected before anything is written.
    file_put_contents($piecePath, 'tampered');
    expect(fn () => $puzzle->export($outDir, $optionsNoOverwrite))
        ->toThrow(\ImagePuzzle\Exception\ExportFailedException::class, 'Existing output cannot be used');
    expect(file_get_contents($piecePath))->toBe('tampered');

    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export with overwrite false to empty directory still writes files', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $options = new ExportOptions(overwrite: false);
    $result = $puzzle->export($outDir, $options);

    $piecePath = $outDir . DIRECTORY_SEPARATOR . 'piece_0_0.png';
    expect($piecePath)->toBeFile();
    expect($result->getFiles())->toHaveCount(2);

    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export manifest source equals puzzle getSourceBasename when from file', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    expect($puzzle->getSourceBasename())->toBe('sample.png');
    $result = $puzzle->export($outDir, new ExportOptions());
    expect($result->getManifest()['source'])->toBe('sample.png');
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export manifest source is null when puzzle from GdImage', function (): void {
    $outDir = getTempDir();
    $image = Fixtures::load(__DIR__ . '/fixtures/sample.png');
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromGd($image, 1, 1);
    expect($puzzle->getSourceBasename())->toBeNull();
    $result = $puzzle->export($outDir, new ExportOptions());
    expect($result->getManifest()['source'])->toBeNull();
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export writes all files inside the output directory', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $result = $puzzle->export($outDir, new ExportOptions());
    $realOutDir = Fixtures::realpath($outDir);
    expect($realOutDir)->not->toBeFalse();
    foreach ($result->getFiles() as $path) {
        expect(str_starts_with(Fixtures::realpath($path), $realOutDir))->toBeTrue();
    }
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('exportParallel with workers 1 produces same result as sequential export', function (): void {
    $outDir = getTempDir();
    $exporter = new \ImagePuzzle\Exporter();
    $options = new ExportOptions();
    $result = $exporter->exportParallel(
        __DIR__ . '/fixtures/sample.png',
        $outDir,
        2,
        2,
        $options,
        1,
    );
    expect($result->getOutputDirectory())->toBe($outDir);
    expect($result->getFiles())->toHaveCount(5);
    $manifest = $result->getManifest();
    expect($manifest['rows'])->toBe(2);
    expect($manifest['cols'])->toBe(2);
    expect($manifest['pieces'])->toHaveCount(4);
    foreach ($result->getFiles() as $path) {
        expect($path)->toBeFile();
    }
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('exportParallel produces same files and manifest as sequential export', function (): void {
    $outDirSeq = getTempDir();
    $outDirPar = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $options = new ExportOptions();
    $resultSeq = $puzzle->export($outDirSeq, $options);
    $exporter = new \ImagePuzzle\Exporter();
    $resultPar = $exporter->exportParallel(
        __DIR__ . '/fixtures/sample.png',
        $outDirPar,
        2,
        2,
        $options,
        1,
    );
    expect($resultPar->getFiles())->toHaveCount(count($resultSeq->getFiles()));
    expect($resultPar->getManifest()['rows'])->toBe($resultSeq->getManifest()['rows']);
    expect($resultPar->getManifest()['cols'])->toBe($resultSeq->getManifest()['cols']);
    expect(Fixtures::pieces($resultPar->getManifest()))->toHaveCount(count(Fixtures::pieces($resultSeq->getManifest())));
    foreach ($resultSeq->getFiles() as $f) {
        @unlink($f);
    }
    foreach ($resultPar->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDirSeq);
    @rmdir($outDirPar);
});

test('exportParallel with 2 workers when pcntl available produces correct manifest and files', function (): void {
    if (!extension_loaded('pcntl') || PHP_SAPI !== 'cli') {
        expect(true)->toBeTrue();
        return;
    }
    $outDir = getTempDir();
    $exporter = new \ImagePuzzle\Exporter();
    $options = new ExportOptions();
    $result = $exporter->exportParallel(
        __DIR__ . '/fixtures/sample.png',
        $outDir,
        2,
        2,
        $options,
        2,
    );
    expect($result->getFiles())->toHaveCount(5);
    expect($result->getManifest()['pieces'])->toHaveCount(4);
    expect($result->getManifestPath())->toBeFile();
    foreach ($result->getFiles() as $path) {
        expect($path)->toBeFile();
    }
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
})->skip(!extension_loaded('pcntl') || PHP_SAPI !== 'cli', 'pcntl and CLI required');

test('exportParallel when worker throws onPieceBeforeSave throws', function (): void {
    if (!extension_loaded('pcntl') || PHP_SAPI !== 'cli') {
        expect(true)->toBeTrue();
        return;
    }
    $outDir = getTempDir();
    $exporter = new \ImagePuzzle\Exporter();
    $options = new ExportOptions(
        onPieceBeforeSave: function (\ImagePuzzle\Piece $piece): void {
            throw new \RuntimeException('Worker abort');
        },
    );
    try {
        $exporter->exportParallel(
            __DIR__ . '/fixtures/sample.png',
            $outDir,
            2,
            2,
            $options,
            2,
        );
        expect(false)->toBeTrue('Expected exception');
    } catch (\ImagePuzzle\Exception\ExportFailedException $e) {
        expect($e->getMessage())->toContain('Parallel export failed')->toContain('RuntimeException: Worker abort');
    } catch (\RuntimeException $e) {
        expect($e->getMessage())->toContain('Worker abort');
    }
    if (is_dir($outDir)) {
        foreach (Fixtures::glob($outDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($outDir);
    }
})->skip(!extension_loaded('pcntl') || PHP_SAPI !== 'cli', 'pcntl and CLI required');

test('export when onPieceBeforeSave throws propagates exception', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $options = new ExportOptions(
        onPieceBeforeSave: function (\ImagePuzzle\Piece $piece): void {
            if ($piece->getRow() === 1 && $piece->getCol() === 0) {
                throw new \RuntimeException('Abort save');
            }
        },
    );
    expect(fn () => $puzzle->export($outDir, $options))
        ->toThrow(\RuntimeException::class, 'Abort save');
    if (is_dir($outDir)) {
        $files = Fixtures::glob($outDir . '/*');
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($outDir);
    }
});

test('export invokes onPieceBeforeSave before each piece write', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 2, 2);
    $beforeCalls = [];
    $options = new ExportOptions(
        onPieceBeforeSave: function (\ImagePuzzle\Piece $piece, string $filepath) use (&$beforeCalls): void {
            $beforeCalls[] = ['row' => $piece->getRow(), 'col' => $piece->getCol(), 'path' => $filepath];
        },
    );
    $result = $puzzle->export($outDir, $options);
    expect($beforeCalls)->toHaveCount(4);
    expect($beforeCalls[0]['row'])->toBe(0);
    expect($beforeCalls[0]['col'])->toBe(0);
    expect($beforeCalls[0]['path'])->toEndWith('piece_0_0.png');
    expect($beforeCalls[3]['row'])->toBe(1);
    expect($beforeCalls[3]['col'])->toBe(1);
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export invokes onPieceAfterSave after each piece write', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $afterCalls = [];
    $options = new ExportOptions(
        onPieceAfterSave: function (\ImagePuzzle\Piece $piece, string $filepath) use (&$afterCalls): void {
            $afterCalls[] = ['row' => $piece->getRow(), 'path' => $filepath];
        },
    );
    $puzzle->export($outDir, $options);
    expect($afterCalls)->toHaveCount(1);
    expect($afterCalls[0]['path'])->toBeFile();
    foreach (Fixtures::glob($outDir . '/*') as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export when onManifestBuild returns non encodable data throws ExportFailedException', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $options = new ExportOptions(
        onManifestBuild: function (array $manifest): array {
            $manifest['extensions'] = ['invalid' => NAN];
            return $manifest;
        },
    );
    expect(fn () => $puzzle->export($outDir, $options))
        ->toThrow(\ImagePuzzle\Exception\ExportFailedException::class, 'Failed to encode manifest');
    if (is_dir($outDir)) {
        foreach (Fixtures::glob($outDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($outDir);
    }
});

test('export onManifestBuild can extend the manifest through extensions only', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $options = new ExportOptions(
        onManifestBuild: /**
         * @param array<string, mixed> $manifest
         * @return array<string, mixed>
         */
        function (array $manifest): array {
            $manifest['extensions'] = ['acme' => ['customKey' => 'customValue', 'pieces' => ['r0c0' => 42]]];
            return $manifest;
        },
    );
    $result = $puzzle->export($outDir, $options);
    expect($result->getManifest())->toMatchArray(['extensions' => ['acme' => ['customKey' => 'customValue', 'pieces' => ['r0c0' => 42]]]]);
    expect(Fixtures::json($result->getManifestPath()))->toMatchArray(['extensions' => ['acme' => ['customKey' => 'customValue', 'pieces' => ['r0c0' => 42]]]]);

    $rejecting = new ExportOptions(onManifestBuild: function (array $manifest): array {
        $manifest['customKey'] = 'x';
        return $manifest;
    });
    expect(fn () => $puzzle->export($outDir, $rejecting))
        ->toThrow(\ImagePuzzle\Exception\ExportFailedException::class, 'only modify the "extensions"');
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('exportParallel with workers zero falls back to sequential export', function (): void {
    $outDir = getTempDir();
    $exporter = new \ImagePuzzle\Exporter();
    $options = new ExportOptions();
    $result = $exporter->exportParallel(
        __DIR__ . '/fixtures/sample.png',
        $outDir,
        2,
        2,
        $options,
        0,
    );
    expect($result->getFiles())->toHaveCount(5);
    expect($result->getManifest()['pieces'])->toHaveCount(4);
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('exportParallel with invalid source path throws', function (): void {
    $outDir = getTempDir();
    $exporter = new \ImagePuzzle\Exporter();
    $options = new ExportOptions();
    expect(fn () => $exporter->exportParallel(
        __DIR__ . '/fixtures/nonexistent.png',
        $outDir,
        1,
        1,
        $options,
        1,
    ))->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'not readable');
    if (is_dir($outDir)) {
        @rmdir($outDir);
    }
});

test('exportParallel with non-image file throws before any output', function (): void {
    $tmp = sys_get_temp_dir() . '/image-puzzle-not-image-' . uniqid() . '.png';
    $outDir = getTempDir();
    try {
        file_put_contents($tmp, 'not an image');
        $exporter = new \ImagePuzzle\Exporter();
        $options = new ExportOptions();
        expect(fn () => $exporter->exportParallel($tmp, $outDir, 1, 1, $options, 2))
            ->toThrow(\ImagePuzzle\Exception\UnsupportedImageTypeException::class, 'Unsupported image type');
        expect(Fixtures::glob($outDir . '/*'))->toBe([]);
    } finally {
        @unlink($tmp);
        if (is_dir($outDir)) {
            @rmdir($outDir);
        }
    }
})->skip(!extension_loaded('pcntl') || PHP_SAPI !== 'cli', 'pcntl and CLI required for getimagesize path');

test('export with unsupported format is rejected when options are built', function (): void {
    $outDir = getTempDir();
    expect(fn () => new ExportOptions(format: 'gif'))
        ->toThrow(\ImagePuzzle\Exception\InvalidArgumentException::class, 'Unsupported export format');
    if (is_dir($outDir)) {
        foreach (Fixtures::glob($outDir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($outDir);
    }
});

test('export with baseDirectory when output inside base succeeds', function (): void {
    $baseDir = getTempDir();
    mkdir($baseDir); // the base directory must exist before export
    $outDir = $baseDir . '/subdir';
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $result = $puzzle->export($outDir, new ExportOptions(), $baseDir);
    expect($result->getOutputDirectory())->toBe($outDir);
    expect($result->getFiles())->toHaveCount(2);
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
    @rmdir($baseDir);
});

test('export with baseDirectory when output outside base throws', function (): void {
    $baseDir = getTempDir();
    mkdir($baseDir, 0755, true);
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    expect(fn () => $puzzle->export($outDir, new ExportOptions(), $baseDir))
        ->toThrow(\ImagePuzzle\Exception\ExportFailedException::class, 'Output directory must be inside base directory');
    @rmdir($baseDir);
    if (is_dir($outDir)) {
        @rmdir($outDir);
    }
});

test('export with baseDirectory when base directory does not exist throws', function (): void {
    $outDir = getTempDir();
    mkdir($outDir, 0755, true);
    $baseDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    expect(fn () => $puzzle->export($outDir, new ExportOptions(), $baseDir))
        ->toThrow(\ImagePuzzle\Exception\ExportFailedException::class, 'Base directory does not exist or is not a directory');
    foreach (Fixtures::glob($outDir . '/*') as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('exportParallel with baseDirectory when output inside base succeeds', function (): void {
    $baseDir = getTempDir();
    mkdir($baseDir); // the base directory must exist before export
    $outDir = $baseDir . '/subdir';
    $exporter = new \ImagePuzzle\Exporter();
    $options = new ExportOptions();
    $result = $exporter->exportParallel(
        __DIR__ . '/fixtures/sample.png',
        $outDir,
        1,
        1,
        $options,
        1,
        null,
        $baseDir,
    );
    expect($result->getOutputDirectory())->toBe($outDir);
    expect($result->getFiles())->toHaveCount(2);
    foreach ($result->getFiles() as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
    @rmdir($baseDir);
});

test('exportParallel with baseDirectory when output outside base throws', function (): void {
    $baseDir = getTempDir();
    mkdir($baseDir, 0755, true);
    $outDir = getTempDir();
    $exporter = new \ImagePuzzle\Exporter();
    $options = new ExportOptions();
    expect(fn () => $exporter->exportParallel(
        __DIR__ . '/fixtures/sample.png',
        $outDir,
        1,
        1,
        $options,
        1,
        null,
        $baseDir,
    ))->toThrow(\ImagePuzzle\Exception\ExportFailedException::class, 'Output directory must be inside base directory');
    @rmdir($baseDir);
    if (is_dir($outDir)) {
        @rmdir($outDir);
    }
});
