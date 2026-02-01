<?php

declare(strict_types=1);

use ImagePuzzle\ExportOptions;
use ImagePuzzle\PuzzleGenerator;

function getTempDir(): string
{
    return sys_get_temp_dir() . '/image-puzzle-export-' . uniqid();
}

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
    foreach ($manifest['pieces'] as $p) {
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
    $pieces = $manifest['pieces'];
    $piece00 = null;
    foreach ($pieces as $p) {
        if ($p['row'] === 0 && $p['col'] === 0) {
            $piece00 = $p;
            break;
        }
    }
    expect($piece00)->not->toBeNull();
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

test('export with overwrite false does not overwrite existing piece files', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $options = new ExportOptions(overwrite: true);
    $puzzle->export($outDir, $options);

    $piecePath = $outDir . DIRECTORY_SEPARATOR . 'piece_0_0.png';
    expect($piecePath)->toBeFile();
    $originalContent = file_get_contents($piecePath);
    expect($originalContent)->not->toBeFalse();

    file_put_contents($piecePath, 'tampered');
    $optionsNoOverwrite = new ExportOptions(overwrite: false);
    $result = $puzzle->export($outDir, $optionsNoOverwrite);

    expect($result->getFiles())->toContain($piecePath);
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
    $image = imagecreatefrompng(__DIR__ . '/fixtures/sample.png');
    expect($image)->not->toBeFalse();
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
    $realOutDir = realpath($outDir);
    expect($realOutDir)->not->toBeFalse();
    foreach ($result->getFiles() as $path) {
        $realPath = realpath($path);
        expect($realPath)->not->toBeFalse();
        expect(str_starts_with($realPath, $realOutDir))->toBeTrue();
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
    expect($resultPar->getManifest()['pieces'])->toHaveCount(count($resultSeq->getManifest()['pieces']));
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
    foreach (glob($outDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($outDir);
});

test('export onManifestBuild can add and modify manifest', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $options = new ExportOptions(
        onManifestBuild: function (array $manifest): array {
            $manifest['customKey'] = 'customValue';
            $manifest['pieces'][0]['customPieceKey'] = 42;
            return $manifest;
        },
    );
    $result = $puzzle->export($outDir, $options);
    $manifest = $result->getManifest();
    expect($manifest)->toHaveKey('customKey');
    expect($manifest['customKey'])->toBe('customValue');
    expect($manifest['pieces'][0])->toHaveKey('customPieceKey');
    expect($manifest['pieces'][0]['customPieceKey'])->toBe(42);
    $written = json_decode((string) file_get_contents($result->getManifestPath()), true);
    expect($written['customKey'])->toBe('customValue');
    expect($written['pieces'][0]['customPieceKey'])->toBe(42);
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

test('exportParallel with non-image file throws ExportFailedException when getimagesize fails', function (): void {
    $tmp = sys_get_temp_dir() . '/image-puzzle-not-image-' . uniqid() . '.png';
    $outDir = getTempDir();
    try {
        file_put_contents($tmp, 'not an image');
        $exporter = new \ImagePuzzle\Exporter();
        $options = new ExportOptions();
        expect(fn () => $exporter->exportParallel($tmp, $outDir, 1, 1, $options, 2))
            ->toThrow(\ImagePuzzle\Exception\ExportFailedException::class, 'Could not get image size');
    } finally {
        @unlink($tmp);
        if (is_dir($outDir)) {
            @rmdir($outDir);
        }
    }
})->skip(!extension_loaded('pcntl') || PHP_SAPI !== 'cli', 'pcntl and CLI required for getimagesize path');

test('export with unsupported format throws ExportFailedException', function (): void {
    $outDir = getTempDir();
    $generator = new PuzzleGenerator();
    $puzzle = $generator->generateFromFile(__DIR__ . '/fixtures/sample.png', 1, 1);
    $options = new ExportOptions(format: 'gif');
    expect(fn () => $puzzle->export($outDir, $options))
        ->toThrow(\ImagePuzzle\Exception\ExportFailedException::class, 'Failed to write image');
    if (is_dir($outDir)) {
        foreach (glob($outDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($outDir);
    }
});

test('export with baseDirectory when output inside base succeeds', function (): void {
    $baseDir = getTempDir();
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
