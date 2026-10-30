<?php

declare(strict_types=1);

use ImagePuzzle\Exception\IllegalActionException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\SourceImage;
use ImagePuzzle\Tests\Game\GameSupport;

/**
 * Every board reachable from the solved sliding board (breadth-first).
 *
 * @return array<string, true>
 */
function reachableSlidingBoards(GameDefinition $definition): array
{
    $rules = $definition->rules();
    $start = $rules->solvedBoard($definition);
    $seen = [(string) json_encode($start) => true];
    $queue = [$start];
    while ($queue !== []) {
        $board = array_shift($queue);
        foreach ($rules->legalActions($definition, $board) as $action) {
            $next = $rules->apply($definition, $board, $action);
            $key = (string) json_encode($next);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $queue[] = $next;
            }
        }
    }

    return $seen;
}

test('sliding scrambles are reproducible, unsolved and reachable by construction', function (): void {
    $custom = ['rows' => 2, 'cols' => 3, 'scrambleMoves' => 30];
    $definition = GameSupport::definition(GameKind::Sliding, 'custom', $custom);
    $rules = $definition->rules();
    $reachable = reachableSlidingBoards($definition);
    // Half of the 6! arrangements are reachable; the parity check must agree with the search.
    expect($reachable)->toHaveCount(360);
    for ($seed = 0; $seed < 60; $seed++) {
        $board = $rules->scramble($definition, $seed);
        expect($board)->toBe($rules->scramble($definition, $seed));
        expect($rules->isComplete($definition, $board))->toBeFalse();
        expect(isset($reachable[(string) json_encode($board)]))->toBeTrue();
    }
    // Short walks that could return to the solved board still end unsolved.
    $short = GameSupport::definition(GameKind::Sliding, 'custom', ['rows' => 2, 'cols' => 2, 'scrambleMoves' => 12]);
    for ($seed = 0; $seed < 40; $seed++) {
        expect($short->rules()->isComplete($short, $short->rules()->scramble($short, $seed)))->toBeFalse();
    }
});

test('sliding import accepts exactly the reachable boards', function (): void {
    $definition = GameSupport::definition(GameKind::Sliding, 'custom', ['rows' => 2, 'cols' => 3, 'scrambleMoves' => 30]);
    $rules = $definition->rules();
    $reachable = reachableSlidingBoards($definition);
    $ids = ['t0', 't1', 't2', 't3', 't4', null];
    $accepted = 0;
    foreach (permutations($ids) as $cells) {
        $key = (string) json_encode(['cells' => $cells]);
        try {
            $rules->validateBoard($definition, ['cells' => $cells]);
            expect(isset($reachable[$key]))->toBeTrue("accepted unreachable {$key}");
            $accepted++;
        } catch (InvalidDefinitionException) {
            expect(isset($reachable[$key]))->toBeFalse("rejected reachable {$key}");
        }
    }
    expect($accepted)->toBe(360);
});

/**
 * @template T
 * @param list<T> $items
 * @return list<list<T>>
 */
function permutations(array $items): array
{
    if (count($items) <= 1) {
        return [$items];
    }
    $out = [];
    foreach ($items as $i => $item) {
        $rest = $items;
        unset($rest[$i]);
        foreach (permutations(array_values($rest)) as $tail) {
            $out[] = [$item, ...$tail];
        }
    }

    return $out;
}

test('sliding allows only tiles next to the gap and completes exactly', function (): void {
    $definition = GameSupport::definition(GameKind::Sliding);
    $rules = $definition->rules();
    $board = ['cells' => ['t0', 't1', 't2', 't3', 't4', 't5', 't6', null, 't7']];
    expect($rules->legalActions($definition, $board))->toBe([
        ['type' => 'slide', 'tile' => 't4'],
        ['type' => 'slide', 'tile' => 't6'],
        ['type' => 'slide', 'tile' => 't7'],
    ]);
    expect(fn () => $rules->apply($definition, $board, ['type' => 'slide', 'tile' => 't0']))->toThrow(IllegalActionException::class, 'not next to the empty cell');
    expect(fn () => $rules->apply($definition, $board, ['type' => 'slide', 'tile' => 't8']))->toThrow(IllegalActionException::class, 'not on the board');
    expect(fn () => $rules->apply($definition, $board, ['type' => 'swap', 'a' => 't7', 'b' => 't6']))->toThrow(IllegalActionException::class, 'does not support "swap"');
    $solved = $rules->apply($definition, $board, ['type' => 'slide', 'tile' => 't7']);
    expect($rules->isComplete($definition, $solved))->toBeTrue();
    expect($rules->progress($definition, $board))->toBe(7 / 8);
    expect($rules->hint($definition, $board))->toBeNull();
    expect($definition->rules()->capabilities()['hint'])->toBeFalse();
    // The input board is not modified.
    expect($board['cells'][7])->toBeNull();
});

test('swap completion compares stable ids even when every tile looks identical', function (): void {
    // A uniform image: all tiles have identical pixels, only ids differ.
    $definition = GameSupport::definition(GameKind::Swap, source: new SourceImage(90, 90, hash('sha256', 'uniform grey')));
    $rules = $definition->rules();
    $board = ['cells' => ['t1', 't0', 't2', 't3', 't4', 't5', 't6', 't7', 't8']];
    expect($rules->isComplete($definition, $board))->toBeFalse();
    expect($rules->hint($definition, $board))->toBe(['type' => 'swap', 'a' => 't1', 'b' => 't0']);
    $done = $rules->apply($definition, $board, ['type' => 'swap', 'a' => 't0', 'b' => 't1']);
    expect($rules->isComplete($definition, $done))->toBeTrue();
    expect(fn () => $rules->apply($definition, $board, ['type' => 'swap', 'a' => 't1', 'b' => 't1']))->toThrow(IllegalActionException::class, 'two different tiles');
    expect(fn () => $rules->apply($definition, $board, ['type' => 'swap', 'a' => 't1', 'b' => 't99']))->toThrow(IllegalActionException::class, 'must be on the board');
    expect(count($rules->legalActions($definition, $board)))->toBe(36);
    for ($seed = 0; $seed < 30; $seed++) {
        expect($rules->isComplete($definition, $rules->scramble($definition, $seed)))->toBeFalse();
    }
});

test('rotation follows the clockwise orientation convention', function (): void {
    $definition = GameSupport::definition(GameKind::Rotation, 'medium');
    $rules = $definition->rules();
    $board = $rules->solvedBoard($definition);
    $sequence = [];
    for ($i = 0; $i < 4; $i++) {
        $board = $rules->apply($definition, $board, ['type' => 'rotate', 'tile' => 't5', 'direction' => 'cw']);
        $orientations = $board['orientations'];
        expect($orientations)->toBeArray();
        $sequence[] = is_array($orientations) ? $orientations[5] : null;
    }
    expect($sequence)->toBe([90, 180, 270, 0]);
    $ccw = $rules->apply($definition, $rules->solvedBoard($definition), ['type' => 'rotate', 'tile' => 't0', 'direction' => 'ccw']);
    expect($ccw['orientations'])->toBe([270, ...array_fill(0, 15, 0)]);
    expect($rules->hint($definition, $ccw))->toBe(['type' => 'rotate', 'tile' => 't0', 'direction' => 'cw']);
    $quarter = $rules->apply($definition, $rules->solvedBoard($definition), ['type' => 'rotate', 'tile' => 't0', 'direction' => 'cw']);
    expect($rules->hint($definition, $quarter))->toBe(['type' => 'rotate', 'tile' => 't0', 'direction' => 'ccw']);
    expect(fn () => $rules->apply($definition, $ccw, ['type' => 'rotate', 'tile' => 't0', 'direction' => 'left']))->toThrow(InvalidDefinitionException::class, 'direction');

    $half = GameSupport::definition(GameKind::Rotation, 'easy');
    $flipped = $half->rules()->apply($half, $half->rules()->solvedBoard($half), ['type' => 'rotate', 'tile' => 't2', 'direction' => 'ccw']);
    expect($flipped['orientations'])->toBe([0, 0, 180, 0, 0, 0, 0, 0, 0]);
    expect(fn () => $half->rules()->validateBoard($half, ['orientations' => [90, 0, 0, 0, 0, 0, 0, 0, 0]]))->toThrow(InvalidDefinitionException::class, 'not an allowed orientation');
    expect(count($half->rules()->legalActions($half, $flipped)))->toBe(9);
});

test('strips move removes and inserts, with explicit remainder handling', function (): void {
    $definition = GameSupport::definition(GameKind::Strips, 'custom', ['count' => 4, 'axis' => 'horizontal'], source: new SourceImage(100, 103));
    expect($definition->crop->toArray())->toBe(['x' => 0, 'y' => 1, 'width' => 100, 'height' => 100]);
    expect(array_map(static fn ($tile): array => $tile->rect->toArray(), $definition->tiles))->toBe([
        ['x' => 0, 'y' => 1, 'width' => 100, 'height' => 25],
        ['x' => 0, 'y' => 26, 'width' => 100, 'height' => 25],
        ['x' => 0, 'y' => 51, 'width' => 100, 'height' => 25],
        ['x' => 0, 'y' => 76, 'width' => 100, 'height' => 25],
    ]);
    $rules = $definition->rules();
    $board = ['order' => ['s2', 's0', 's1', 's3']];
    expect($rules->apply($definition, $board, ['type' => 'move', 'strip' => 's2', 'to' => 2]))->toBe(['order' => ['s0', 's1', 's2', 's3']]);
    expect($rules->apply($definition, $board, ['type' => 'move', 'strip' => 's3', 'to' => 0]))->toBe(['order' => ['s3', 's2', 's0', 's1']]);
    expect(fn () => $rules->apply($definition, $board, ['type' => 'move', 'strip' => 's2', 'to' => 0]))->toThrow(IllegalActionException::class, 'already at position 0');
    expect(fn () => $rules->apply($definition, $board, ['type' => 'move', 'strip' => 's2', 'to' => 4]))->toThrow(InvalidDefinitionException::class, 'between 0 and 3');
    expect($rules->hint($definition, $board))->toBe(['type' => 'move', 'strip' => 's0', 'to' => 0]);
    expect(count($rules->legalActions($definition, $board)))->toBe(12);

    $vertical = GameSupport::definition(GameKind::Strips, 'custom', ['count' => 3, 'axis' => 'vertical'], source: new SourceImage(100, 50));
    expect([$vertical->rows, $vertical->cols])->toBe([1, 3]);
    expect($vertical->crop->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => 99, 'height' => 50]);
    expect(fn () => GameDefinition::fromArray(GameSupport::tampered($vertical, ['rules' => ['axis' => 'horizontal']])))->toThrow(InvalidDefinitionException::class, 'board.cols = 1');
});

test('actions are validated as external data', function (array $action, string $exception): void {
    $definition = GameSupport::definition(GameKind::Swap);
    expect(fn () => $definition->rules()->apply($definition, $definition->initial, GameSupport::stringKeys($action)))->toThrow($exception);
})->with([
    'missing type' => [['a' => 't0', 'b' => 't1'], InvalidDefinitionException::class],
    'numeric id' => [['type' => 'swap', 'a' => 0, 'b' => 't1'], InvalidDefinitionException::class],
    'uppercase id' => [['type' => 'swap', 'a' => 'T0', 'b' => 't1'], InvalidDefinitionException::class],
    'long id' => [['type' => 'swap', 'a' => str_repeat('a', 40), 'b' => 't1'], InvalidDefinitionException::class],
    'extra field' => [['type' => 'swap', 'a' => 't0', 'b' => 't1', 'by' => 'me'], InvalidDefinitionException::class],
    'unsupported type' => [['type' => 'slide', 'tile' => 't0'], IllegalActionException::class],
]);
