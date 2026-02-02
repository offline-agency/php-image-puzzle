<?php

declare(strict_types=1);

use ImagePuzzle\ExportResult;

test('constructor and getters', function (): void {
    $outputDir = '/tmp/out';
    $files = ['/tmp/out/piece_0_0.png', '/tmp/out/manifest.json'];
    $manifestPath = '/tmp/out/manifest.json';
    $manifest = ['source' => 'sample.png', 'rows' => 2, 'cols' => 2, 'pieces' => []];

    $result = new ExportResult($outputDir, $files, $manifestPath, $manifest);

    expect($result->getOutputDirectory())->toBe($outputDir);
    expect($result->getFiles())->toBe($files);
    expect($result->getManifestPath())->toBe($manifestPath);
    expect($result->getManifest())->toBe($manifest);
});

test('getManifest returns same reference', function (): void {
    $manifest = ['key' => 'value'];
    $result = new ExportResult('/out', [], '/out/m.json', $manifest);
    expect($result->getManifest())->toBe($manifest);
});

test('getFiles returns empty array when given empty', function (): void {
    $result = new ExportResult('/out', [], '/out/manifest.json', ['pieces' => []]);
    expect($result->getFiles())->toBe([]);
});

test('getFiles returns same list reference', function (): void {
    $files = ['/out/piece_0_0.png'];
    $result = new ExportResult('/out', $files, '/out/m.json', []);
    expect($result->getFiles())->toBe($files);
});

test('getFiles includes manifest path', function (): void {
    $manifestPath = '/tmp/out/manifest.json';
    $files = ['/tmp/out/piece_0_0.png', $manifestPath];
    $manifest = ['source' => null, 'rows' => 1, 'cols' => 1, 'pieces' => []];
    $result = new ExportResult('/tmp/out', $files, $manifestPath, $manifest);
    expect($result->getFiles())->toContain($manifestPath);
    expect($result->getFiles())->toHaveCount(2);
});

test('getManifest contains sourceWidth and sourceHeight', function (): void {
    $manifest = [
        'source' => 'sample.png',
        'rows' => 2,
        'cols' => 2,
        'sourceWidth' => 32,
        'sourceHeight' => 32,
        'pieces' => [],
    ];
    $result = new ExportResult('/out', [], '/out/m.json', $manifest);
    expect($result->getManifest())->toHaveKeys(['sourceWidth', 'sourceHeight']);
    expect($result->getManifest()['sourceWidth'])->toBe(32);
    expect($result->getManifest()['sourceHeight'])->toBe(32);
});
