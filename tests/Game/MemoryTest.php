<?php

declare(strict_types=1);

use ImagePuzzle\Exception\IllegalActionException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameEngine;
use ImagePuzzle\Game\GameFactory;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\GameState;
use ImagePuzzle\Game\SourceImage;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * Memory: card generation, the flip/match/mismatch state machine and state
 * restoration in partial states.
 */

/**
 * @param array<string, mixed> $custom
 */
function memoryDefinition(string $preset = 'easy', array $custom = [], int $seed = 3, int $width = 640, int $height = 480): GameDefinition
{
    return (new GameFactory())->create(GameKind::Memory, new SourceImage($width, $height), 'photo.png', $preset, $custom, $seed);
}

/**
 * @param array<string, mixed> $board
 * @return array<string, mixed>
 */
function flip(GameDefinition $definition, array $board, string $card): array
{
    return $definition->rules()->apply($definition, $board, ['type' => 'flip', 'card' => $card]);
}

describe('generation', function (): void {
    it('deals two cards per pair with stable IDs and one image region per pair', function (string $preset, int $cards, array $regions): void {
        $definition = memoryDefinition($preset);
        expect($definition->tiles)->toHaveCount($cards)
            ->and($definition->rules)->toBe(['regions' => $regions]);
        foreach ($definition->tiles as $i => $tile) {
            $pair = intdiv($i, 2);
            expect($tile->id)->toBe("p{$pair}-" . ($i % 2 === 0 ? 'a' : 'b'))
                ->and($tile->home)->toBe($i)
                ->and($tile->attributes)->toBe(['pair' => "p{$pair}", 'face' => 'image']);
        }
        expect($definition->tiles[0]->rect)->toEqual($definition->tiles[1]->rect)
            ->and($definition->tiles[0]->rect)->not->toEqual($definition->tiles[2]->rect);
    })->with([
        'easy 3x4: 6 pairs' => ['easy', 12, ['rows' => 2, 'cols' => 3]],
        'medium 4x4: 8 pairs' => ['medium', 16, ['rows' => 2, 'cols' => 4]],
        'hard 4x6: 12 pairs' => ['hard', 24, ['rows' => 3, 'cols' => 4]],
    ]);

    it('picks the region grid closest to square cells', function (int $count, int $width, int $height, array $expected): void {
        expect(GameFactory::regions($count, $width, $height))->toBe($expected);
    })->with([
        'landscape 6' => [6, 640, 480, [2, 3]],
        'portrait 6' => [6, 480, 640, [3, 2]],
        'square 4' => [4, 500, 500, [2, 2]],
        'prime 7' => [7, 700, 100, [1, 7]],
        'wide 12' => [12, 1920, 480, [2, 6]],
    ]);

    it('crops the image to equal regions, centred', function (): void {
        $definition = memoryDefinition('easy', width: 641, height: 481);
        expect($definition->crop->toArray())->toBe(['x' => 1, 'y' => 0, 'width' => 639, 'height' => 480]);
    });

    it('shows a text label on the second card of each pair', function (): void {
        $labels = ['Rome', 'Paris', 'Tōkyō', 'Lima', 'Oslo', 'Cairo'];
        $definition = memoryDefinition('custom', ['rows' => 3, 'cols' => 4, 'labels' => $labels]);
        expect($definition->tiles[0]->attributes)->toBe(['pair' => 'p0', 'face' => 'image'])
            ->and($definition->tiles[5]->attributes)->toBe(['pair' => 'p2', 'face' => 'text', 'text' => 'Tōkyō']);
        expect(GameDefinition::fromJson($definition->toJson())->id)->toBe($definition->id);
    });

    it('rejects impossible boards and labels', function (array $custom, string $class, string $message): void {
        expect(fn () => memoryDefinition('custom', Fixtures::object($custom)))->toThrow($class, $message);
    })->with([
        'odd count' => [['rows' => 3, 'cols' => 3], InvalidArgumentException::class, 'even number'],
        'too large' => [['rows' => 9, 'cols' => 2], InvalidDefinitionException::class, 'between 2 and 8'],
        'missing label' => [['rows' => 2, 'cols' => 2, 'labels' => ['a']], InvalidArgumentException::class, 'one label per pair (2), got 1'],
        'empty label' => [['rows' => 2, 'cols' => 2, 'labels' => ['a', '']], InvalidDefinitionException::class, 'labels[1]'],
        'control character' => [['rows' => 2, 'cols' => 2, 'labels' => ['a', "b\nc"]], InvalidDefinitionException::class, 'control characters'],
        'padded' => [['rows' => 2, 'cols' => 2, 'labels' => ['a', ' b']], InvalidDefinitionException::class, 'surrounding spaces'],
        'too long' => [['rows' => 2, 'cols' => 2, 'labels' => ['a', str_repeat('é', 61)]], InvalidDefinitionException::class, 'longer than 120'],
        'unknown key' => [['rows' => 2, 'cols' => 2, 'pairs' => 2], InvalidDefinitionException::class, 'pairs'],
    ]);

    it('is deterministic per seed and shuffles the layout', function (): void {
        expect(memoryDefinition(seed: 5)->initial)->toBe(memoryDefinition(seed: 5)->initial)
            ->and(memoryDefinition(seed: 5)->initial)->not->toBe(memoryDefinition(seed: 6)->initial)
            ->and(memoryDefinition()->initial['matched'])->toBe([])
            ->and(memoryDefinition()->initial['revealed'])->toBe([]);
    });
});

describe('rules', function (): void {
    it('matches a pair, keeps a mismatch face up and turns it down on the next flip', function (): void {
        $definition = memoryDefinition();
        $board = $definition->initial;

        $board = flip($definition, $board, 'p0-a');
        expect($board['revealed'])->toBe(['p0-a']);
        $board = flip($definition, $board, 'p1-a');
        expect($board['revealed'])->toBe(['p0-a', 'p1-a'])->and($board['matched'])->toBe([]);

        // The next flip turns the mismatch face down first.
        $board = flip($definition, $board, 'p0-b');
        expect($board['revealed'])->toBe(['p0-b']);
        $board = flip($definition, $board, 'p0-a');
        expect($board['revealed'])->toBe([])->and($board['matched'])->toBe(['p0-b', 'p0-a']);
        expect($definition->rules()->progress($definition, $board))->toBe(2 / 12);
    });

    it('lets a card of a pending mismatch be flipped again, alone', function (): void {
        $definition = memoryDefinition();
        $board = flip($definition, flip($definition, $definition->initial, 'p0-a'), 'p1-a');
        expect(flip($definition, $board, 'p1-a')['revealed'])->toBe(['p1-a']);
    });

    it('rejects flips that the state machine forbids', function (string $card, string $message): void {
        $definition = memoryDefinition();
        $board = flip($definition, flip($definition, $definition->initial, 'p0-a'), 'p0-b');
        $board = flip($definition, $board, 'p1-a');
        expect(fn () => flip($definition, $board, $card))->toThrow(IllegalActionException::class, $message);
    })->with([
        'matched card' => ['p0-a', 'already matched'],
        'the face-up card' => ['p1-a', 'already face up'],
        'unknown card' => ['p9-a', 'not on the board'],
    ]);

    it('rejects unsupported and malformed actions', function (array $action, string $class, string $message): void {
        $definition = memoryDefinition();
        expect(fn () => $definition->rules()->apply($definition, $definition->initial, Fixtures::object($action)))->toThrow($class, $message);
    })->with([
        'swap' => [['type' => 'swap', 'a' => 'p0-a', 'b' => 'p0-b'], IllegalActionException::class, 'does not support "swap"'],
        'conceal' => [['type' => 'conceal'], IllegalActionException::class, 'does not support "conceal"'],
        'extra field' => [['type' => 'flip', 'card' => 'p0-a', 'peek' => true], InvalidDefinitionException::class, 'peek'],
        'bad id' => [['type' => 'flip', 'card' => 'P0'], InvalidDefinitionException::class, 'not a valid identifier'],
    ]);

    it('lists every flippable card in board order', function (): void {
        $definition = memoryDefinition();
        $rules = $definition->rules();
        expect($rules->legalActions($definition, $definition->initial))->toHaveCount(12);
        $board = flip($definition, $definition->initial, 'p2-b');
        $legal = $rules->legalActions($definition, $board);
        expect($legal)->toHaveCount(11)
            ->and(in_array(['type' => 'flip', 'card' => 'p2-b'], $legal, true))->toBeFalse();
        $board = flip($definition, flip($definition, $board, 'p2-a'), 'p3-a');
        expect($rules->legalActions($definition, $board))->toHaveCount(9);
    });

    it('has no hints, undo or redo, and completes when every pair is matched', function (): void {
        $definition = memoryDefinition();
        $engine = new GameEngine();
        $state = $engine->start($definition);
        expect($engine->hint($definition, $state))->toBeNull();
        $state = $engine->apply($definition, $state, ['type' => 'flip', 'card' => 'p0-a']);
        expect(fn () => $engine->undo($definition, $state))->toThrow(IllegalActionException::class);
        for ($pair = 0; $pair < 6; $pair++) {
            if ($pair > 0) {
                $state = $engine->apply($definition, $state, ['type' => 'flip', 'card' => "p{$pair}-a"]);
            }
            $state = $engine->apply($definition, $state, ['type' => 'flip', 'card' => "p{$pair}-b"]);
        }
        expect($state->completed)->toBeTrue()->and($state->moves)->toBe(12);
        expect($definition->toArray()['capabilities'])->toBe(['undo' => false, 'redo' => false, 'reset' => true, 'shuffle' => true, 'hint' => false]);
    });
});

describe('state', function (): void {
    it('restores partially revealed and matched states exactly', function (): void {
        $definition = memoryDefinition();
        $engine = new GameEngine();
        $state = $engine->start($definition);
        foreach (['p0-a', 'p0-b', 'p1-a', 'p2-b'] as $card) {
            $state = $engine->apply($definition, $state, ['type' => 'flip', 'card' => $card]);
        }
        expect($state->board['matched'])->toBe(['p0-a', 'p0-b'])->and($state->board['revealed'])->toBe(['p1-a', 'p2-b']);
        $restored = GameState::fromJson($definition, $state->toJson());
        expect($restored->board)->toBe($state->board)->and($restored->moves)->toBe(4);
        // Play continues identically from the restored state.
        expect($engine->apply($definition, $restored, ['type' => 'flip', 'card' => 'p1-b'])->board)
            ->toBe($engine->apply($definition, $state, ['type' => 'flip', 'card' => 'p1-b'])->board);
    });

    it('rejects impossible boards', function (array $change, string $message): void {
        $definition = memoryDefinition();
        $board = [...$definition->initial, ...$change];
        expect(fn () => $definition->rules()->validateBoard($definition, $board))->toThrow(InvalidDefinitionException::class, $message);
    })->with([
        'half a pair matched' => [['matched' => ['p0-a']], 'without its partner'],
        'matched card face up' => [['matched' => ['p0-a', 'p0-b'], 'revealed' => ['p0-a']], 'matched card p0-a'],
        'unresolved pair' => [['revealed' => ['p0-a', 'p0-b']], 'should be matched'],
        'three face up' => [['revealed' => ['p0-a', 'p1-a', 'p2-a']], 'more than 2'],
        'repeated card' => [['revealed' => ['p0-a', 'p0-a']], 'repeats'],
        'unknown card' => [['matched' => ['p9-a', 'p9-b']], 'unknown card'],
        'missing card' => [['cards' => ['p0-a']], 'must list 12'],
        'extra field' => [['peek' => true], 'peek'],
    ]);

    it('rejects definitions whose cards were tampered with', function (int $tile, string $field, mixed $value, string $message): void {
        $doc = memoryDefinition()->toArray();
        $tiles = array_values(Fixtures::object($doc['tiles']));
        $card = Fixtures::object($tiles[$tile] ?? null);
        $card[$field] = $value;
        $tiles[$tile] = $card;
        $doc['tiles'] = $tiles;
        expect(fn () => GameDefinition::fromArray($doc))->toThrow(InvalidDefinitionException::class, $message);
    })->with([
        'renamed card' => [0, 'id', 'q0-a', 'must be "p0-a"'],
        'wrong pair' => [1, 'pair', 'p1', 'pair must be "p0"'],
        'text on the first card' => [0, 'face', 'text', 'first card of a pair shows the image'],
        'unknown face' => [1, 'face', 'video', 'face must be "image" or "text"'],
        'text without face text' => [1, 'text', 'x', 'unknown field'],
        'region moved' => [2, 'rect', ['x' => 0, 'y' => 0, 'width' => 213, 'height' => 240], 'does not show region 1'],
    ]);
});
