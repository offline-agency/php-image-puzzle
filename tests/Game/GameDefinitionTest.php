<?php

declare(strict_types=1);

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameFactory;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\Modes;
use ImagePuzzle\Game\SourceImage;
use ImagePuzzle\Tests\Game\GameSupport;
use ImagePuzzle\Tools\SchemaValidator;

dataset('grid kinds', [GameKind::Sliding, GameKind::Swap, GameKind::Rotation, GameKind::Strips]);

test('definitions round-trip through JSON with a content-bound id', function (GameKind $kind): void {
    foreach (['easy', 'medium', 'hard'] as $preset) {
        $definition = GameSupport::definition($kind, $preset);
        expect($definition->id)->toMatch('/^gd1-[0-9a-f]{64}$/');
        $again = GameDefinition::fromJson($definition->toJson());
        expect($again->toJson())->toBe($definition->toJson());
        expect(SchemaValidator::forRepository()->validate($definition->toArray(), 'game/v1/definition.schema.json'))->toBe([]);
    }
})->with('grid kinds');

test('the id changes with the source digest, seed, preset and configuration', function (): void {
    $base = GameSupport::definition(GameKind::Swap);
    $ids = [
        $base->id,
        GameSupport::definition(GameKind::Swap, seed: 2)->id,
        GameSupport::definition(GameKind::Swap, 'medium')->id,
        GameSupport::definition(GameKind::Swap, source: new SourceImage(640, 480, hash('sha256', 'other')))->id,
        GameSupport::definition(GameKind::Swap, source: new SourceImage(640, 480, null))->id,
        GameSupport::definition(GameKind::Swap, 'custom', ['rows' => 3, 'cols' => 3, 'assistance' => ['preview' => false, 'numbers' => true, 'highlightCorrect' => true]])->id,
    ];
    expect(array_unique($ids))->toHaveCount(count($ids));
    expect(GameSupport::definition(GameKind::Swap)->id)->toBe($base->id);
});

test('tampered, stale or inconsistent documents are rejected', function (array $changes, string $message): void {
    $definition = GameSupport::definition(GameKind::Sliding);
    expect(fn () => GameDefinition::fromArray(GameSupport::tampered($definition, $changes)))
        ->toThrow(InvalidDefinitionException::class, $message);
})->with([
    'changed seed' => [['seed' => 999], 'does not match its content'],
    'stale version' => [['version' => 2], 'Unsupported game definition version 2'],
    'other format' => [['format' => 'something'], 'definition.format'],
    'unknown field' => [['cheat' => true], 'unknown field(s): cheat'],
    'unknown kind' => [['kind' => 'chess'], 'not a supported game kind'],
    'wrong capabilities' => [['capabilities' => ['undo' => true, 'redo' => true, 'reset' => true, 'shuffle' => true, 'hint' => true]], 'capabilities do not match'],
    'wrong actions' => [['actions' => ['swap']], 'actions do not match'],
    'generator bump' => [['generator' => ['algorithm' => 2]], 'Unsupported generator algorithm'],
    'path traversal media' => [['media' => ['image' => '../secret.png']], 'media.image'],
    'absolute media' => [['media' => ['image' => '/etc/passwd']], 'media.image'],
    'scheme media' => [['media' => ['image' => 'javascript:alert(1)']], 'media.image'],
    'http media' => [['media' => ['image' => 'http://example.test/a.png']], 'media.image'],
    'bad digest' => [['source' => ['width' => 640, 'height' => 480, 'sha256' => 'XYZ']], 'sha256'],
    'crop outside source' => [['source' => ['width' => 10, 'height' => 10, 'sha256' => null]], 'board.crop'],
    'solved initial' => [['initial' => ['cells' => ['t0', 't1', 't2', 't3', 't4', 't5', 't6', 't7', null]]], 'already solved'],
    'unknown preset' => [['preset' => ['name' => 'insane', 'version' => 1]], 'Unknown preset'],
]);

test('duplicate and missing tile ids are rejected', function (): void {
    $definition = GameSupport::definition(GameKind::Swap);
    $tiles = array_map(static fn (ImagePuzzle\Game\Tile $tile): array => $tile->toArray(), $definition->tiles);
    $duplicate = $tiles;
    $duplicate[1]['id'] = 't0';
    expect(fn () => GameDefinition::fromArray(GameSupport::tampered($definition, ['tiles' => $duplicate])))
        ->toThrow(InvalidDefinitionException::class, 'Duplicate tile id "t0"');
    $missing = $tiles;
    array_pop($missing);
    expect(fn () => GameDefinition::fromArray(GameSupport::tampered($definition, ['tiles' => $missing])))
        ->toThrow(InvalidDefinitionException::class, 'needs exactly 9 tiles');
    $moved = $tiles;
    $moved[1]['rect'] = ['x' => $definition->tiles[1]->rect->x + 1] + $definition->tiles[1]->rect->toArray();
    expect(fn () => GameDefinition::fromArray(GameSupport::tampered($definition, ['tiles' => $moved])))
        ->toThrow(InvalidDefinitionException::class, 'does not show cell 1');
});

test('oversized and malformed JSON is rejected before decoding into a game', function (): void {
    expect(fn () => GameDefinition::fromJson('{'))->toThrow(InvalidDefinitionException::class, 'not valid JSON');
    expect(fn () => GameDefinition::fromJson(str_repeat(' ', GameDefinition::MAX_JSON_BYTES + 1)))
        ->toThrow(InvalidDefinitionException::class, 'exceeds');
    expect(fn () => GameDefinition::fromJson('[1,2]'))->toThrow(InvalidDefinitionException::class, 'must be an object');
});

test('grid modes crop the remainder symmetrically into equal cells', function (): void {
    $definition = GameSupport::definition(GameKind::Swap, source: new SourceImage(803, 601));
    expect($definition->crop->toArray())->toBe(['x' => 1, 'y' => 0, 'width' => 801, 'height' => 600]);
    foreach ($definition->tiles as $tile) {
        expect([$tile->rect->width, $tile->rect->height])->toBe([267, 200]);
    }
    $quarter = GameSupport::definition(GameKind::Rotation, 'medium', source: new SourceImage(803, 601));
    expect($quarter->crop->toArray())->toBe(['x' => 101, 'y' => 0, 'width' => 600, 'height' => 600]);
    $half = GameSupport::definition(GameKind::Rotation, 'easy', source: new SourceImage(803, 601));
    expect([$half->tiles[0]->rect->width, $half->tiles[0]->rect->height])->toBe([267, 200]);
});

test('factory parameters are validated', function (): void {
    $factory = new GameFactory();
    $source = GameSupport::source();
    expect(fn () => $factory->create(GameKind::Swap, $source, 'a.png', 'custom'))->toThrow(InvalidArgumentException::class, 'explicit parameters');
    expect(fn () => $factory->create(GameKind::Swap, $source, 'a.png', 'easy', ['rows' => 2]))->toThrow(InvalidArgumentException::class, 'only accepted with the custom preset');
    expect(fn () => $factory->create(GameKind::Swap, $source, 'a.png', 'custom', ['rows' => 2, 'cols' => 2, 'colour' => 'red']))->toThrow(InvalidDefinitionException::class, 'unknown field(s): colour');
    expect(fn () => $factory->create(GameKind::Sliding, $source, 'a.png', 'custom', ['rows' => 1, 'cols' => 5, 'scrambleMoves' => 5]))->toThrow(InvalidDefinitionException::class);
    expect(fn () => $factory->create(GameKind::Swap, $source, 'a.png', 'custom', ['rows' => 1, 'cols' => 1]))->toThrow(InvalidDefinitionException::class, 'at least two tiles');
    expect(fn () => $factory->create(GameKind::Swap, new SourceImage(3, 3), 'a.png', 'medium'))->toThrow(InvalidArgumentException::class, 'cannot hold');
    expect(fn () => $factory->create(GameKind::Rotation, $source, 'a.png', 'custom', ['rows' => 2, 'cols' => 2, 'orientations' => [90, 180]]))->toThrow(InvalidDefinitionException::class, 'subset');
    expect(fn () => $factory->create(GameKind::Jigsaw, $source, 'a.png'))->toThrow(InvalidArgumentException::class, 'jigsawFromManifest');
    expect(fn () => $factory->create(GameKind::Swap, $source, 'a.png', 'legendary'))->toThrow(InvalidArgumentException::class, 'No "legendary" preset');
});

test('quarter turns on non-square cells are rejected on import', function (): void {
    $definition = GameSupport::definition(GameKind::Rotation, 'easy');
    expect(fn () => GameDefinition::fromArray(GameSupport::tampered($definition, ['rules' => ['orientations' => [0, 90, 180, 270]]])))
        ->toThrow(InvalidDefinitionException::class, 'Quarter turns need square cells');
});

test('modes not shipped in this version are reported, not silently mapped', function (): void {
    expect(fn () => Modes::rules(GameKind::Tangram))->toThrow(InvalidDefinitionException::class, 'not available');
    expect(array_map(static fn (GameKind $kind): string => $kind->value, Modes::available()))
        ->toBe(['sliding', 'swap', 'rotation', 'strips', 'jigsaw', 'nonogram']);
});

test('the schema rejects documents that contradict their kind', function (): void {
    $validator = SchemaValidator::forRepository();
    $definition = GameSupport::definition(GameKind::Sliding)->toArray();
    expect($validator->validate(['actions' => ['swap']] + $definition, 'game/v1/definition.schema.json'))->not->toBe([]);
    expect($validator->validate(['rules' => ['empty' => 't8']] + $definition, 'game/v1/definition.schema.json'))->not->toBe([]);
    expect($validator->validate(['cells' => ['t0', 't0']], 'game/v1/board.schema.json#/$defs/swap'))->not->toBe([]);
    expect($validator->validate(['type' => 'rotate', 'tile' => 't0', 'direction' => 'up'], 'game/v1/action.schema.json'))->not->toBe([]);
});
