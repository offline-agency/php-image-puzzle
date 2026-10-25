<?php

declare(strict_types=1);

use ImagePuzzle\Config\PuzzleConfig;
use ImagePuzzle\Exception\IllegalActionException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameFactory;
use ImagePuzzle\Game\Rules\JigsawRules;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\Tests\Game\GameSupport;
use ImagePuzzle\Tests\Support\Fixtures;
use ImagePuzzle\Tools\SchemaValidator;

/**
 * @return array<string, mixed>
 */
function exportedManifest(string $dir, string $topology, int $rows, int $cols): array
{
    $source = Fixtures::coordinateImage(160, 120, true);
    imagepng($source, $dir . '/source.png');
    $config = PuzzleConfig::fromArray([
        'configVersion' => 2,
        'rows' => $rows,
        'cols' => $cols,
        'edge' => ['type' => 'jigsaw'],
        'geometry' => ['seed' => 4, 'topology' => $topology],
    ]);
    $result = PuzzleGenerator::generateAndExport($dir . '/source.png', $dir . '/pieces', $rows, $cols, $config->edgeStyle(), $config->exportOptions(), geometry: $config->geometryOptions());

    return Fixtures::json($result->getManifestPath());
}

test('jigsaw definitions wrap real export manifests of every topology', function (string $topology, int $rows, int $cols): void {
    $dir = Fixtures::tempDir('jigsaw-game');
    try {
        $manifest = exportedManifest($dir, $topology, $rows, $cols);
        $definition = (new GameFactory())->jigsawFromManifest($manifest, 'source.png', 'easy', 3, Fixtures::sha256($dir . '/source.png'), 'pieces/');
        expect(SchemaValidator::forRepository()->validate($definition->toArray(), 'game/v1/definition.schema.json'))->toBe([]);
        expect(GameDefinition::fromJson($definition->toJson())->id)->toBe($definition->id);
        $pieces = $manifest['pieces'];
        expect($pieces)->toBeArray();
        expect($definition->tiles)->toHaveCount(is_array($pieces) ? count($pieces) : -1);
        foreach ($definition->tiles as $tile) {
            $image = $tile->attribute('image');
            expect(is_string($image) && is_file($dir . '/' . $image))->toBeTrue();
            expect($tile->attribute('path'))->toBeString();
        }
        // Playing every hint solves the puzzle; each placement snaps.
        $rules = $definition->rules();
        $board = $definition->initial;
        expect($rules->progress($definition, $board))->toBe(0.0);
        while (($hint = $rules->hint($definition, $board)) !== null) {
            $board = $rules->apply($definition, $board, $hint);
        }
        expect($rules->isComplete($definition, $board))->toBeTrue();
        expect($board)->toBe($rules->solvedBoard($definition));
    } finally {
        Fixtures::removeDir($dir);
    }
})->with([
    'rect' => ['rect', 2, 3],
    'hex' => ['hex', 2, 2],
    'triangle' => ['triangle', 2, 2],
]);

test('snapping uses an inclusive per-axis tolerance and locks placed pieces', function (): void {
    $dir = Fixtures::tempDir('jigsaw-snap');
    try {
        $definition = (new GameFactory())->jigsawFromManifest(exportedManifest($dir, 'rect', 2, 2), 'source.png', 'custom', 1, null, 'pieces/', 5);
        $rules = $definition->rules();
        $home = JigsawRules::rendered($definition->tiles[0]);
        $id = $definition->tiles[0]->id;
        $board = $definition->initial;

        $near = $rules->apply($definition, $board, ['type' => 'place', 'piece' => $id, 'x' => $home->x + 5, 'y' => $home->y - 5]);
        expect(GameSupport::entry($near, 'pieces', 0))->toBe(['x' => $home->x, 'y' => $home->y, 'placed' => true]);
        $far = $rules->apply($definition, $board, ['type' => 'place', 'piece' => $id, 'x' => $home->x + 6, 'y' => $home->y]);
        expect(GameSupport::entry($far, 'pieces', 0))->toBe(['x' => $home->x + 6, 'y' => $home->y, 'placed' => false]);
        expect(fn () => $rules->apply($definition, $near, ['type' => 'place', 'piece' => $id, 'x' => 300, 'y' => 0]))->toThrow(IllegalActionException::class, 'already placed');
        expect(fn () => $rules->apply($definition, $board, ['type' => 'place', 'piece' => $id, 'x' => -6, 'y' => 0]))->toThrow(IllegalActionException::class, 'play area');
        expect(fn () => $rules->validateBoard($definition, $far))->not->toThrow(InvalidDefinitionException::class);

        // The scramble keeps every piece in the tray, outside all snap zones.
        $tray = JigsawRules::tray($definition);
        foreach ([0, 1, 2, 3, 4] as $seed) {
            $scramble = $rules->scramble($definition, $seed);
            $pieces = $scramble['pieces'];
            expect($pieces)->toBeArray();
            foreach (is_array($pieces) ? $pieces : [] as $piece) {
                expect(is_array($piece) && $piece['placed'] === false && $piece['x'] >= $tray->x)->toBeTrue();
            }
            $rules->validateBoard($definition, $scramble);
        }
    } finally {
        Fixtures::removeDir($dir);
    }
});

test('jigsaw manifests and pieces are validated', function (): void {
    $dir = Fixtures::tempDir('jigsaw-invalid');
    try {
        $manifest = exportedManifest($dir, 'rect', 2, 2);
        $factory = new GameFactory();
        expect(fn () => $factory->jigsawFromManifest(['manifestVersion' => 1] + $manifest, 'source.png'))->toThrow(InvalidDefinitionException::class, 'version 2 manifest');
        expect(fn () => $factory->jigsawFromManifest(['pieces' => []] + $manifest, 'source.png'))->toThrow(InvalidDefinitionException::class, 'no pieces');
        $pieces = $manifest['pieces'];
        if (!is_array($pieces) || !is_array($pieces[0])) {
            throw new LogicException('pieces');
        }
        $pieces[0]['filename'] = '../escape.png';
        expect(fn () => $factory->jigsawFromManifest(['pieces' => $pieces] + $manifest, 'source.png'))->toThrow(InvalidDefinitionException::class, 'safe relative path');
        expect(fn () => $factory->jigsawFromManifest($manifest, 'source.png', 'custom', 0, null, '', 1000))->toThrow(InvalidDefinitionException::class);
    } finally {
        Fixtures::removeDir($dir);
    }
});
