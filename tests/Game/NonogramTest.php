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
use ImagePuzzle\Game\Nonogram\NonogramGenerator;
use ImagePuzzle\Game\Nonogram\NonogramSolver;
use ImagePuzzle\Game\SourceImage;
use ImagePuzzle\Geometry\Bounds;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * Nonogram: clue generation, the bounded uniqueness solver (checked against
 * brute force), image conversion and the rules.
 */

/**
 * A black-on-white picture: filled where $pattern has "#", one 10x10 px
 * block per character.
 *
 * @param list<string> $pattern
 */
function nonogramImage(array $pattern, int $cell = 10): GdImage
{
    $rows = count($pattern);
    $cols = strlen($pattern[0]);
    $image = Fixtures::canvas($cols * $cell, $rows * $cell);
    $white = Fixtures::color($image, 255, 255, 255);
    $black = Fixtures::color($image, 0, 0, 0);
    foreach ($pattern as $r => $line) {
        for ($c = 0; $c < $cols; $c++) {
            imagefilledrectangle($image, $c * $cell, $r * $cell, ($c + 1) * $cell - 1, ($r + 1) * $cell - 1, $line[$c] === '#' ? $black : $white);
        }
    }

    return $image;
}

/**
 * @param list<string> $pattern
 * @param array<string, mixed> $custom
 */
function nonogramDefinition(array $pattern, array $custom = []): GameDefinition
{
    $image = nonogramImage($pattern);

    return (new GameFactory())->nonogram(new SourceImage(imagesx($image), imagesy($image)), $image, 'picture.png', 'custom', $custom + ['rows' => count($pattern), 'cols' => strlen($pattern[0])]);
}

/**
 * Number of grids (up to 2) matching the clues, by enumeration.
 *
 * @param list<list<int>> $rowClues
 * @param list<list<int>> $colClues
 */
function bruteForceCount(array $rowClues, array $colClues): int
{
    $rows = count($rowClues);
    $cols = count($colClues);
    $count = 0;
    for ($mask = 0; $mask < 1 << ($rows * $cols); $mask++) {
        $grid = '';
        for ($i = 0; $i < $rows * $cols; $i++) {
            $grid .= (($mask >> $i) & 1) === 1 ? '1' : '0';
        }
        if (NonogramGenerator::cluesOf($grid, $rows, $cols) === [$rowClues, $colClues]) {
            $count++;
            if ($count === 2) {
                return 2;
            }
        }
    }

    return $count;
}

describe('line solver', function (): void {
    it('fixes the cells every placement agrees on', function (string $cells, array $clues, ?string $expected): void {
        $line = array_map(static fn (string $c): int => $c === '.' ? -1 : (int) $c, str_split($cells));
        $solved = NonogramSolver::solveLine($line, array_values(array_map(static fn (mixed $v): int => is_int($v) ? $v : 0, $clues)));
        expect($solved === null ? null : implode('', array_map(static fn (int $v): string => $v === -1 ? '.' : (string) $v, $solved)))->toBe($expected);
    })->with([
        'empty clue empties the line' => ['.....', [], '00000'],
        'full line' => ['.....', [5], '11111'],
        'overlap fixes the middle' => ['.....', [3], '..1..'],
        'repeated runs that fit exactly' => ['.....', [1, 1, 1], '10101'],
        'known filled cell anchors a run' => ['1....', [2], '11000'],
        'known empty cell splits space' => ['..0..', [2], '..0..'],
        'nothing deducible' => ['.....', [1], '.....'],
        'contradiction: filled cell, empty clue' => ['..1..', [], null],
        'contradiction: run does not fit' => ['.0.0.', [2], null],
        'contradiction: too many filled' => ['111..', [2], null],
    ]);
});

describe('solver', function (): void {
    it('agrees with brute force on every 3x3 grid', function (): void {
        for ($mask = 0; $mask < 512; $mask++) {
            $grid = '';
            for ($i = 0; $i < 9; $i++) {
                $grid .= (($mask >> $i) & 1) === 1 ? '1' : '0';
            }
            [$rows, $cols] = NonogramGenerator::cluesOf($grid, 3, 3);
            $result = (new NonogramSolver($rows, $cols))->classify();
            $expected = bruteForceCount($rows, $cols) === 1 ? NonogramSolver::UNIQUE : NonogramSolver::MULTIPLE;
            expect($result['status'])->toBe($expected, "grid {$grid}");
            if ($expected === NonogramSolver::UNIQUE) {
                expect($result['solutions'])->toBe([$grid]);
            } else {
                expect($result['solutions'])->toHaveCount(2);
                foreach ($result['solutions'] as $solution) {
                    expect(NonogramGenerator::cluesOf($solution, 3, 3))->toBe([$rows, $cols]);
                }
            }
        }
    });

    it('agrees with brute force on seeded 4x4 grids', function (): void {
        mt_srand(4);
        for ($n = 0; $n < 120; $n++) {
            $grid = '';
            for ($i = 0; $i < 16; $i++) {
                $grid .= mt_rand(0, 1) === 1 ? '1' : '0';
            }
            [$rows, $cols] = NonogramGenerator::cluesOf($grid, 4, 4);
            $status = (new NonogramSolver($rows, $cols))->classify()['status'];
            expect($status)->toBe(bruteForceCount($rows, $cols) === 1 ? NonogramSolver::UNIQUE : NonogramSolver::MULTIPLE, "grid {$grid}");
        }
    });

    it('reports clues without a solution', function (): void {
        expect((new NonogramSolver([[2], []], [[1], []]))->classify()['status'])->toBe(NonogramSolver::NONE);
    });

    it('stops at the budget and never calls an undecided grid unique', function (): void {
        // A 2x2 diagonal has two solutions; with no budget nothing is decided.
        $result = (new NonogramSolver([[1], [1]], [[1], [1]], budget: 1))->classify();
        expect($result['status'])->toBe(NonogramSolver::UNVERIFIED)
            ->and($result['work'])->toBe(2);
        expect((new NonogramSolver([[1], [1]], [[1], [1]]))->classify()['status'])->toBe(NonogramSolver::MULTIPLE);
    });

    it('stays within its budget on 30x30 noise', function (): void {
        mt_srand(30);
        $grid = '';
        for ($i = 0; $i < 900; $i++) {
            $grid .= mt_rand(0, 1) === 1 ? '1' : '0';
        }
        [$rows, $cols] = NonogramGenerator::cluesOf($grid, 30, 30);
        $result = (new NonogramSolver($rows, $cols, budget: 2000))->classify();
        expect($result['work'])->toBeLessThanOrEqual(2001)
            ->and($result['status'])->toBeIn([NonogramSolver::UNIQUE, NonogramSolver::MULTIPLE, NonogramSolver::UNVERIFIED]);
    });
});

describe('generator', function (): void {
    it('averages cell luma over transparent pixels on white', function (): void {
        $image = Fixtures::canvas(20, 10);
        imagealphablending($image, false);
        imagefilledrectangle($image, 0, 0, 9, 9, Fixtures::color($image, 0, 0, 0));
        imagefilledrectangle($image, 10, 0, 19, 9, Fixtures::color($image, 0, 0, 0, 127));
        expect(NonogramGenerator::lumas($image, new Bounds(0, 0, 20, 10), 1, 2))->toBe([0, 255]);
    });

    it('finds the Otsu threshold between two groups', function (): void {
        $level = NonogramGenerator::otsu([10, 12, 11, 200, 210, 205]);
        expect($level)->toBeGreaterThan(12)->toBeLessThanOrEqual(200);
    });

    it('crops square cells from the centre', function (): void {
        expect(NonogramGenerator::crop(105, 50, 4, 8)->toArray())->toBe(['x' => 4, 'y' => 1, 'width' => 96, 'height' => 48]);
        expect(fn () => NonogramGenerator::crop(5, 5, 10, 10))->toThrow(InvalidArgumentException::class, 'cannot hold');
    });

    it('generates the pictured grid and proves it unique', function (): void {
        $pattern = ['#....', '##...', '###..', '####.', '#####'];
        $definition = nonogramDefinition($pattern);
        expect($definition->rules['solution'])->toBe('1000011000111001111011111')
            ->and($definition->rules['uniqueness'])->toBe('unique')
            ->and($definition->rules['clues'])->toBe(['rows' => [[1], [2], [3], [4], [5]], 'cols' => [[5], [4], [3], [2], [1]]])
            ->and($definition->tiles)->toBe([]);
    });

    it('labels a grid that cannot be made unique', function (): void {
        // A checkerboard's clues admit its colour-inverted twin, at every threshold.
        $definition = nonogramDefinition(['#.', '.#']);
        expect($definition->rules['uniqueness'])->toBe('multiple');
        expect(fn () => nonogramDefinition(['#.', '.#'], ['requireUnique' => true]))->toThrow(InvalidArgumentException::class, 'proven unique');
    });

    it('labels an undecided grid as unverified, never unique', function (): void {
        $image = nonogramImage(['#.#.', '.#.#', '#..#', '.##.']);
        $definition = (new GameFactory())->nonogram(new SourceImage(40, 40), $image, 'p.png', 'custom', ['rows' => 4, 'cols' => 4], budget: 1);
        expect($definition->rules['uniqueness'])->toBe('unverified');
    });

    it('rejects images without filled cells and bad parameters', function (callable $make, string $class, string $message): void {
        expect($make)->toThrow($class, $message);
    })->with([
        'blank' => [fn () => nonogramDefinition(['....', '....']), InvalidArgumentException::class, 'no filled cells'],
        'threshold' => [fn () => nonogramDefinition(['#.', '.#'], ['threshold' => 0]), InvalidArgumentException::class, 'threshold'],
        'threshold word' => [fn () => nonogramDefinition(['#.', '.#'], ['threshold' => 'dark']), InvalidArgumentException::class, 'threshold'],
        // Malformed parameters are reported like every other mode's (Doc).
        'too large' => [fn () => nonogramDefinition(array_fill(0, 31, str_repeat('#', 31))), InvalidDefinitionException::class, 'between 2 and 30'],
        'unknown parameter' => [fn () => nonogramDefinition(['#.', '.#'], ['colour' => 1]), InvalidDefinitionException::class, 'colour'],
    ]);

    it('honours invert and an explicit threshold', function (): void {
        $definition = nonogramDefinition(['#....', '##...', '###..', '####.', '#####'], ['invert' => true, 'threshold' => 128]);
        expect($definition->rules['solution'])->toBe('0111100111000110000100000')
            ->and($definition->rules['threshold'])->toBe(128)
            ->and($definition->rules['invert'])->toBeTrue();
    });
});

describe('rules', function (): void {
    it('starts unknown, marks cells and completes on the clues', function (): void {
        $definition = nonogramDefinition(['#....', '##...', '###..', '####.', '#####']);
        $engine = new GameEngine();
        $state = $engine->start($definition);
        expect($state->board)->toBe(['cells' => str_repeat('.', 25)]);
        $state = $engine->apply($definition, $state, ['type' => 'mark', 'cell' => 1, 'state' => 'crossed']);
        expect($state->board['cells'])->toStartWith('.x...');
        $hints = 0;
        while (!$state->completed) {
            $hint = $engine->hint($definition, $state);
            expect($hint)->not->toBeNull();
            assert($hint !== null);
            $state = $engine->apply($definition, $hint['state'], $hint['action']);
            $hints++;
        }
        expect($hints)->toBe(15)->and($state->completed)->toBeTrue();
    });

    it('accepts any grid that satisfies the clues of a non-unique puzzle', function (): void {
        $definition = nonogramDefinition(['#.', '.#']);
        $engine = new GameEngine();
        $state = $engine->start($definition);
        // The generated solution is the diagonal; the anti-diagonal also fits the clues.
        $state = $engine->apply($definition, $state, ['type' => 'mark', 'cell' => 1, 'state' => 'filled']);
        $state = $engine->apply($definition, $state, ['type' => 'mark', 'cell' => 2, 'state' => 'filled']);
        expect($state->completed)->toBeTrue();
    });

    it('rejects illegal and malformed actions', function (array $action, string $class, string $message): void {
        $definition = nonogramDefinition(['#.', '.#']);
        $board = (new GameEngine())->start($definition)->board;
        expect(fn () => $definition->rules()->apply($definition, $board, Fixtures::object($action)))->toThrow($class, $message);
    })->with([
        'same state' => [['type' => 'mark', 'cell' => 0, 'state' => 'unknown'], IllegalActionException::class, 'already unknown'],
        'outside' => [['type' => 'mark', 'cell' => 4, 'state' => 'filled'], IllegalActionException::class, 'outside'],
        'state' => [['type' => 'mark', 'cell' => 0, 'state' => 'grey'], InvalidDefinitionException::class, 'filled, crossed or unknown'],
        'type' => [['type' => 'swap', 'a' => 'x', 'b' => 'y'], IllegalActionException::class, 'does not support'],
        'extra field' => [['type' => 'mark', 'cell' => 0, 'state' => 'filled', 'all' => true], InvalidDefinitionException::class, 'all'],
    ]);

    it('restores partially solved states and rejects malformed boards', function (): void {
        $definition = nonogramDefinition(['#....', '##...', '###..', '####.', '#####']);
        $engine = new GameEngine();
        $state = $engine->apply($definition, $engine->start($definition), ['type' => 'mark', 'cell' => 0, 'state' => 'filled']);
        $restored = GameState::fromJson($definition, $state->toJson());
        expect($restored->board)->toBe($state->board)->and($restored->moves)->toBe(1);
        $doc = $state->toArray();
        foreach (['short' => '1....', 'symbol' => str_repeat('?', 25)] as $cells) {
            $doc['board'] = ['cells' => $cells];
            expect(fn () => GameState::fromArray($definition, $doc))->toThrow(InvalidDefinitionException::class, 'board.cells');
        }
    });

    it('rejects definitions whose clues, solution or label were changed', function (string $field, mixed $value, string $message): void {
        $doc = nonogramDefinition(['#....', '##...', '###..', '####.', '#####'])->toArray();
        $rules = Fixtures::object($doc['rules']);
        $rules[$field] = $value;
        $doc['rules'] = $rules;
        expect(fn () => GameDefinition::fromArray($doc))->toThrow(InvalidDefinitionException::class, $message);
    })->with([
        'clues' => ['clues', ['rows' => [[1], [2], [3], [4], [4]], 'cols' => [[5], [4], [3], [2], [1]]], 'do not match'],
        'solution' => ['solution', str_repeat('0', 25), 'no filled cell'],
        'label' => ['uniqueness', 'proven', 'unique, multiple or unverified'],
        'id' => ['threshold', 7, 'does not match its content'],
    ]);

    it('is registered as a playable kind', function (): void {
        expect(ImagePuzzle\Game\Modes::available())->toContain(GameKind::Nonogram)
            ->and(nonogramDefinition(['#.', '.#'])->rules()->capabilities()['shuffle'])->toBeFalse();
    });
});
