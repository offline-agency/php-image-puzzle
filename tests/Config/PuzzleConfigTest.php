<?php

declare(strict_types=1);

use ImagePuzzle\Config\PuzzleConfig;
use ImagePuzzle\EdgeStyle\JigsawStyle;
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Geometry\Topology\HexagonalTopology;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\Tests\Support\Fixtures;
use ImagePuzzle\Tools\SchemaValidator;

function configRoot(): string
{
    return dirname(__DIR__, 2);
}

test('shipped v2 examples load without warnings and round-trip through toArray', function (string $file): void {
    $config = PuzzleConfig::fromFile(configRoot() . '/config/' . $file);
    expect($config->warnings())->toBe([]);
    $again = PuzzleConfig::fromJson($config->toJson());
    expect($again->toArray())->toBe($config->toArray());
    expect(SchemaValidator::forRepository()->validate($config->toArray(), 'v2/puzzle-config.schema.json'))->toBe([]);
})->with(['puzzle-config.example.json', 'puzzle-config-hex.example.json']);

test('the hex example builds hexagonal geometry and generation export options', function (): void {
    $config = PuzzleConfig::fromFile(configRoot() . '/config/puzzle-config-hex.example.json');
    expect($config->geometryOptions()->topology)->toBeInstanceOf(HexagonalTopology::class);
    expect($config->geometryOptions()->seed)->toBe(7);
    expect($config->edgeStyle())->toBeInstanceOf(JigsawStyle::class);
    $options = $config->exportOptions();
    expect($options->getFormat())->toBe('webp');
    expect($options->getQuality())->toBe(80);
    expect($options->getPublishMode())->toBe('generation');
    expect($config->outputDirectory())->toBeNull();
});

test('a legacy 1.x config is migrated with explicit warnings', function (): void {
    $config = PuzzleConfig::fromFile(configRoot() . '/tests/fixtures/config/legacy-v1.json');
    expect($config->warnings())->toHaveCount(2);
    expect($config->warnings()[0])->toContain('read as a 1.x config');
    expect($config->toArray()['configVersion'])->toBe(2);
    expect($config->toArray()['geometry'])->toBe(['seed' => 1, 'samples' => 4, 'topology' => 'rect']);
    expect($config->outputDirectory())->toBe('output/puzzle');
});

test('lossy legacy fields are reported, never silently dropped', function (): void {
    $config = PuzzleConfig::fromArray([
        'input' => 'a.png',
        'rows' => 2,
        'cols' => 2,
        'edge' => ['type' => 'rounded_corners', 'options' => ['radius' => 5, 'color' => 'red']],
        'output' => ['directory' => 'out', 'compression' => 'max'],
    ]);
    $warnings = implode("\n", $config->warnings());
    expect($warnings)->toContain('edge.options color')->toContain('output keys compression');
    expect($config->edgeStyle())->toBeInstanceOf(RoundedCornersStyle::class);
    expect($config->toArray()['edge'])->toBe(['type' => 'rounded_corners', 'options' => ['radius' => 5]]);
    expect($config->toArray()['output'])->toBe(['directory' => 'out']);
});

test('invalid v2 configs are rejected with the offending path', function (array $data, string $message): void {
    $base = ['configVersion' => 2, 'rows' => 2, 'cols' => 3, 'edge' => ['type' => 'jigsaw']];
    expect(fn () => PuzzleConfig::fromArray(array_replace($base, $data)))->toThrow(InvalidArgumentException::class, $message);
    // The schema rejects the same documents.
    expect(SchemaValidator::forRepository()->validate(array_replace($base, $data), 'v2/puzzle-config.schema.json'))->not->toBe([]);
})->with([
    'wrong version' => [['configVersion' => 3], 'configVersion must be 2'],
    'unknown top key' => [['colour' => 'red'], 'config has unsupported keys: colour'],
    'zero rows' => [['rows' => 0], 'config.rows'],
    'string cols' => [['cols' => '3'], 'config.cols'],
    'bad topology' => [['geometry' => ['topology' => 'voronoi']], 'config.geometry.topology'],
    'float seed' => [['geometry' => ['seed' => 1.5]], 'config.geometry.seed'],
    'samples range' => [['geometry' => ['samples' => 17]], 'samples'],
    'unknown geometry key' => [['geometry' => ['jitter' => 1]], 'config.geometry has unsupported keys'],
    'unknown output key' => [['output' => ['zip' => true]], 'config.output has unsupported keys'],
    'png quality' => [['output' => ['quality' => 50]], 'config.output: Quality 50'],
    'string overwrite' => [['output' => ['overwrite' => 'yes']], 'config.output.overwrite'],
    'path in pattern' => [['output' => ['filenamePattern' => 'a/{id}.png']], 'config.output'],
    'manifest extension' => [['output' => ['manifestFilename' => 'manifest.txt']], 'config.output'],
    'edge radius string' => [['edge' => ['type' => 'rounded_corners', 'options' => ['radius' => '4']]], 'radius must be an integer'],
    'edge unknown type' => [['edge' => ['type' => 'zigzag']], 'Unknown edge type'],
]);

test('non-object JSON is rejected', function (string $json): void {
    expect(fn () => PuzzleConfig::fromJson($json))->toThrow(InvalidArgumentException::class);
})->with(['[1,2]', '"x"', '{bad', '7']);

test('identical configs reproduce identical piece files', function (): void {
    $dir = Fixtures::tempDir('config-repro');
    try {
        $source = Fixtures::writePng(Fixtures::coordinateImage(80, 60), $dir . '/s.png');
        $config = PuzzleConfig::fromArray(['configVersion' => 2, 'rows' => 3, 'cols' => 3, 'edge' => ['type' => 'jigsaw'], 'geometry' => ['seed' => 11, 'topology' => 'triangle']]);
        $hashes = [];
        foreach (['a', 'b'] as $run) {
            $copy = PuzzleConfig::fromJson($config->toJson());
            $result = PuzzleGenerator::generateAndExport($source, "{$dir}/{$run}", 3, 3, $copy->edgeStyle(), $copy->exportOptions(), geometry: $copy->geometryOptions());
            $hashes[$run] = array_map(static fn (string $f): string => hash_file('sha256', $f), $result->getFiles());
            sort($hashes[$run]);
        }
        expect($hashes['a'])->toBe($hashes['b']);
    } finally {
        Fixtures::removeDir($dir);
    }
});

test('the schema validator rejects documents that violate the manifest schema', function (): void {
    $validator = SchemaValidator::forRepository();
    expect($validator->checkSchemaFiles())->toBe([]);
    expect($validator->validate(['manifestVersion' => 2], 'v2/manifest.schema.json'))->not->toBe([]);
    $dir = Fixtures::tempDir('schema-manifest');
    try {
        $source = Fixtures::writePng(Fixtures::coordinateImage(40, 30), $dir . '/s.png');
        $result = PuzzleGenerator::generateAndExport($source, $dir . '/out', 2, 2, new JigsawStyle());
        expect($validator->validateFile($result->getManifestPath(), 'v2/manifest.schema.json'))->toBe([]);
        $tampered = json_decode((string) file_get_contents($result->getManifestPath()), false, 64, JSON_THROW_ON_ERROR);
        $tampered->pieces[0]->extra = true;
        file_put_contents($dir . '/tampered.json', json_encode($tampered));
        expect($validator->validateFile($dir . '/tampered.json', 'v2/manifest.schema.json'))->not->toBe([]);
    } finally {
        Fixtures::removeDir($dir);
    }
});
