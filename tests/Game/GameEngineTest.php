<?php

declare(strict_types=1);

use ImagePuzzle\Exception\IllegalActionException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\GameEngine;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\GameState;
use ImagePuzzle\Tests\Game\GameSupport;
use ImagePuzzle\Tools\ConformanceFixtures;
use ImagePuzzle\Tools\SchemaValidator;

test('actions, undo and redo update board, history and counters', function (): void {
    $definition = GameSupport::definition(GameKind::Strips);
    $engine = new GameEngine();
    $start = $engine->start($definition);
    $hint = $definition->rules()->hint($definition, $start->board);
    expect($hint)->not->toBeNull();
    $moved = $engine->apply($definition, $start, $hint ?? []);
    expect([$moved->moves, count($moved->past), count($moved->future)])->toBe([1, 1, 0]);
    $undone = $engine->undo($definition, $moved);
    expect($undone->board)->toBe($start->board);
    expect([$undone->moves, count($undone->past), count($undone->future)])->toBe([2, 0, 1]);
    $redone = $engine->redo($definition, $undone);
    expect($redone->board)->toBe($moved->board);
    expect($redone->moves)->toBe(3);
    // A new action clears the redo branch.
    $branch = $engine->apply($definition, $undone, $definition->rules()->legalActions($definition, $undone->board)[0]);
    expect($branch->future)->toBe([]);
    expect(fn () => $engine->undo($definition, $start))->toThrow(IllegalActionException::class, 'Nothing to undo');
    expect(fn () => $engine->redo($definition, $moved))->toThrow(IllegalActionException::class, 'Nothing to redo');
});

test('history is bounded and completion blocks further actions', function (): void {
    $definition = GameSupport::definition(GameKind::Rotation, 'easy');
    $engine = new GameEngine();
    $state = $engine->start($definition);
    // Turning t0 twice returns it to its orientation; repeat past the history limit.
    for ($i = 0; $i < GameState::HISTORY_LIMIT + 20; $i++) {
        $state = $engine->apply($definition, $state, ['type' => 'rotate', 'tile' => 't3', 'direction' => 'cw']);
        if ($state->completed) {
            break;
        }
    }
    expect(count($state->past))->toBeLessThanOrEqual(GameState::HISTORY_LIMIT);
    while (($hint = $definition->rules()->hint($definition, $state->board)) !== null) {
        $state = $engine->apply($definition, $state, $hint);
    }
    expect($state->completed)->toBeTrue();
    expect(fn () => $engine->apply($definition, $state, ['type' => 'rotate', 'tile' => 't0', 'direction' => 'cw']))->toThrow(IllegalActionException::class, 'already complete');
    expect(fn () => $engine->undo($definition, $state))->toThrow(IllegalActionException::class);
});

test('reset, shuffle and hint follow the documented semantics', function (): void {
    $definition = GameSupport::definition(GameKind::Swap);
    $engine = new GameEngine();
    $state = $engine->apply($definition, $engine->start($definition), ['type' => 'swap', 'a' => 't0', 'b' => 't1']);
    expect($engine->reset($definition)->board)->toBe($definition->initial);
    expect($engine->reset($definition)->moves)->toBe(0);
    expect($engine->shuffle($definition, 77)->board)->toBe($engine->shuffle($definition, 77)->board);
    expect($engine->shuffle($definition, 77)->board)->toBe($definition->rules()->scramble($definition, 77));
    $hinted = $engine->hint($definition, $state);
    expect($hinted)->not->toBeNull();
    expect($hinted['state']->hintsUsed ?? null)->toBe(1);
    expect($hinted['state']->board ?? null)->toBe($state->board);
    $sliding = GameSupport::definition(GameKind::Sliding);
    expect($engine->hint($sliding, $engine->start($sliding)))->toBeNull();
});

test('state export and import round-trip and match the schema', function (): void {
    $definition = GameSupport::definition(GameKind::Sliding);
    $engine = new GameEngine();
    $state = $engine->start($definition);
    $state = $engine->apply($definition, $state, $definition->rules()->legalActions($definition, $state->board)[0]);
    $json = $state->toJson();
    expect(SchemaValidator::forRepository()->validate($state->toArray(), 'game/v1/state.schema.json'))->toBe([]);
    expect(GameState::fromJson($definition, $json)->toJson())->toBe($json);
});

test('incompatible or corrupt imported state is rejected', function (callable $mutate, string $message): void {
    $definition = GameSupport::definition(GameKind::Sliding);
    $engine = new GameEngine();
    $state = $engine->start($definition);
    $state = $engine->apply($definition, $state, $definition->rules()->legalActions($definition, $state->board)[0]);
    $doc = $state->toArray();
    expect(fn () => GameState::fromArray($definition, $mutate($doc)))->toThrow(InvalidDefinitionException::class, $message);
})->with([
    'other puzzle' => [fn (array $doc): array => ['definitionId' => 'gd1-' . str_repeat('0', 64)] + $doc, 'another puzzle'],
    'stale version' => [fn (array $doc): array => ['version' => 0] + $doc, 'state.version'],
    'kind mismatch' => [fn (array $doc): array => ['kind' => 'swap'] + $doc, 'kind does not match'],
    'false completion' => [fn (array $doc): array => ['completed' => true] + $doc, 'completed does not match'],
    'negative moves' => [fn (array $doc): array => ['moves' => -1] + $doc, 'moves'],
    'unreachable board' => [fn (array $doc): array => ['board' => ['cells' => ['t1', 't0', 't2', 't3', 't4', 't5', 't6', 't7', null]]] + $doc, 'not reachable'],
    'corrupt history' => [fn (array $doc): array => ['history' => ['past' => [['cells' => []]], 'future' => []]] + $doc, 'board.cells'],
    'history too long' => [fn (array $doc): array => ['history' => ['past' => array_fill(0, GameState::HISTORY_LIMIT + 1, $doc['board']), 'future' => []]] + $doc, 'more than'],
    'unknown field' => [fn (array $doc): array => ['score' => 10] + $doc, 'unknown field(s): score'],
]);

test('importing a corrupt state leaves the current game untouched', function (): void {
    $definition = GameSupport::definition(GameKind::Swap);
    $engine = new GameEngine();
    $current = $engine->apply($definition, $engine->start($definition), ['type' => 'swap', 'a' => 't0', 'b' => 't1']);
    $before = $current->toJson();
    try {
        $current = GameState::fromJson($definition, '{"format":"image-puzzle/game-state","version":1}');
    } catch (InvalidDefinitionException) {
        // Expected: the caller keeps $current.
    }
    expect($current->toJson())->toBe($before);
});

test('committed conformance fixtures match the PHP rules', function (): void {
    foreach (ConformanceFixtures::build() as $name => $document) {
        $path = dirname(__DIR__, 2) . '/conformance/' . $name;
        $expected = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        expect(file_get_contents($path))->toBe($expected, "conformance/{$name} is stale: run composer conformance");
    }
});
