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
use ImagePuzzle\Game\Rules\TangramRules;
use ImagePuzzle\Game\SourceImage;
use ImagePuzzle\Game\Tangram\Tangram;
use ImagePuzzle\Tests\Game\GameSupport;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * Tangram: the seven-piece partition, the target library, snapping and the
 * geometric completion check.
 */

/**
 * @param array<string, mixed> $custom
 */
function tangramDefinition(string $preset = 'easy', array $custom = [], int $seed = 3, int $width = 640, int $height = 480): GameDefinition
{
    return (new GameFactory())->create(GameKind::Tangram, new SourceImage($width, $height), 'photo.png', $preset, $custom, $seed);
}

/**
 * Board with the pieces given as id => pose replaced.
 *
 * @param array<string, array{x: int, y: int, rotation: int, flipped: bool}> $poses
 * @param array<string, mixed>|null $board
 * @return array<string, mixed>
 */
function tangramBoard(GameDefinition $definition, array $poses, ?array $board = null): array
{
    $board ??= $definition->rules()->solvedBoard($definition);
    $pieces = Fixtures::listOf($board['pieces'] ?? null);
    foreach ($poses as $id => $pose) {
        $index = array_search($id, Tangram::ids(), true);
        $pieces[(int) $index] = $pose;
    }

    return ['pieces' => $pieces];
}

/**
 * Pose of piece $index in a board.
 *
 * @param array<string, mixed> $board
 * @return array{x: int, y: int, rotation: int, flipped: bool}
 */
function tangramPose(array $board, int $index): array
{
    $pose = Fixtures::object(Fixtures::listOf($board['pieces'] ?? null)[$index] ?? null);

    return ['x' => Fixtures::int($pose, 'x'), 'y' => Fixtures::int($pose, 'y'), 'rotation' => Fixtures::int($pose, 'rotation'), 'flipped' => ($pose['flipped'] ?? null) === true];
}

/**
 * @param array{x: int, y: int, rotation: int, flipped: bool} $pose
 * @return array<string, mixed>
 */
function poseAction(string $piece, array $pose): array
{
    return ['type' => 'pose', 'piece' => $piece] + $pose;
}

/**
 * Pose laying piece $id on $slot at a 120 px unit.
 *
 * @param array{piece: string, shape: string, bound: bool, x: int, y: int, rotation: int, flipped: bool} $slot
 * @return array{x: int, y: int, rotation: int, flipped: bool}
 */
function fitOn(string $id, array $slot): array
{
    return Tangram::fit($id, $slot, 120) ?? throw new LogicException("{$id} does not fit slot {$slot['piece']}");
}

describe('geometry', function (): void {
    it('partitions the square into seven pieces of total area 16 units without overlap', function (): void {
        $area = 0.0;
        $polygons = [];
        foreach (Tangram::ids() as $id) {
            [$cx, $cy] = Tangram::homeCentroid($id, 0, 0, 6);
            $polygon = Tangram::polygon($id, 6, $cx, $cy, 0, false);
            $area += abs(Tangram::doubleArea($polygon)) / 2;
            $polygons[] = $polygon;
            // The posed polygon is the solved one.
            expect(Tangram::distance($polygon, array_map(static fn (array $v): array => [(float) $v[0], (float) $v[1]], Tangram::home($id, 0, 0, 6))))->toBeLessThan(Tangram::EPSILON);
        }
        expect($area)->toEqualWithDelta(16 * 36, 1e-9);
        foreach ($polygons as $i => $a) {
            foreach (array_slice($polygons, $i + 1) as $b) {
                expect(Tangram::overlap($a, $b, 1e-6))->toBeFalse();
            }
        }
    });

    it('counts real overlap but not shared edges or vertices', function (): void {
        $square = static fn (float $x, float $y): array => [[$x, $y], [$x + 10.0, $y], [$x + 10.0, $y + 10.0], [$x, $y + 10.0]];
        expect(Tangram::overlap($square(0, 0), $square(10, 0), 1e-6))->toBeFalse()
            ->and(Tangram::overlap($square(0, 0), $square(10, 10), 1e-6))->toBeFalse()
            ->and(Tangram::overlap($square(0, 0), $square(9.99, 0), 1e-6))->toBeTrue()
            ->and(Tangram::overlap($square(0, 0), $square(0, 0), 1e-6))->toBeTrue();
    });

    it('rotates by exact eighth turns, flips around the centroid and keeps the area', function (): void {
        $base = Tangram::polygon('parallelogram', 6, 100, 100, 0, false);
        for ($rotation = 0; $rotation < 8; $rotation++) {
            foreach ([false, true] as $flipped) {
                $polygon = Tangram::polygon('parallelogram', 6, 100, 100, $rotation, $flipped);
                expect(abs(Tangram::doubleArea($polygon)))->toEqualWithDelta(abs(Tangram::doubleArea($base)), 1e-9);
            }
        }
        // Eight eighth turns are the identity; four are a half turn, which the
        // parallelogram is symmetric under but its mirror image is not.
        expect(Tangram::distance(Tangram::polygon('parallelogram', 6, 100, 100, 4, false), $base))->toBeLessThan(Tangram::EPSILON)
            ->and(Tangram::distance(Tangram::polygon('parallelogram', 6, 100, 100, 0, true), $base))->toBeGreaterThan(1.0);
    });

    it('validates every target in the library', function (string $target): void {
        foreach ([6, 12, 30, 120] as $unit) {
            expect(Tangram::validate($target, $unit))->toBe([]);
        }
    })->with(array_keys(Tangram::TARGETS));

    it('rejects unknown targets honestly', function (): void {
        expect(fn () => Tangram::slots('swan', 0, 0, 6))->toThrow(InvalidArgumentException::class, 'Other silhouettes are not recognised');
        expect(fn () => tangramDefinition('custom', ['target' => 'swan', 'snap' => 30]))->toThrow(InvalidArgumentException::class, 'supported targets: square, arrow, chevron');
    });
});

describe('generation', function (): void {
    it('cuts the largest centred square whose side is a multiple of 24', function (int $width, int $height, array $crop, int $unit): void {
        $definition = tangramDefinition(width: $width, height: $height);
        expect($definition->crop->toArray())->toBe($crop)
            ->and($definition->rules['unit'])->toBe($unit)
            ->and($definition->rows)->toBe(4)->and($definition->cols)->toBe(4);
    })->with([
        'landscape' => [640, 480, ['x' => 80, 'y' => 0, 'width' => 480, 'height' => 480], 120],
        'portrait, remainder cropped' => [100, 130, ['x' => 2, 'y' => 17, 'width' => 96, 'height' => 96], 24],
        'smallest' => [48, 48, ['x' => 0, 'y' => 0, 'width' => 48, 'height' => 48], 12],
    ]);

    it('records each piece with its shape, solved box and outline path for vector export', function (): void {
        $definition = tangramDefinition(width: 96, height: 96);
        expect(array_map(static fn ($tile): string => $tile->id, $definition->tiles))->toBe(Tangram::ids());
        $tile = $definition->tiles[0];
        expect($tile->home)->toBe(0)
            ->and($tile->rect->toArray())->toBe(['x' => 0, 'y' => 0, 'width' => 96, 'height' => 48])
            ->and($tile->attributes)->toBe(['shape' => 'large', 'path' => 'M 0 0 L 96 0 L 48 48 Z']);
        expect($definition->tiles[5]->attributes['path'])->toBe('M 48 48 L 72 24 L 96 48 L 72 72 Z');
    });

    it('derives snap from the preset as a share of the unit', function (string $preset, int $snap, bool $preview): void {
        $definition = tangramDefinition($preset);
        expect($definition->rules['snap'])->toBe($snap)
            ->and($definition->rules['target'])->toBe('square')
            ->and($definition->assistance['preview'])->toBe($preview);
    })->with([
        'easy' => ['easy', 60, true],
        'medium' => ['medium', 36, true],
        'hard' => ['hard', 18, false],
    ]);

    it('places the tray below the target and the play area around both', function (): void {
        $definition = tangramDefinition('custom', ['target' => 'chevron', 'snap' => 30], width: 96, height: 96);
        // chevron spans units 0..4 x 0..6; tray 12x6 units centred at x = 2 units, u = 24 px.
        expect($definition->rules['tray'])->toBe(['x' => 48 - 144, 'y' => 7 * 24, 'width' => 288, 'height' => 144])
            ->and($definition->rules['area'])->toBe(['x' => -96 - 24, 'y' => -24, 'width' => 288 + 48, 'height' => 7 * 24 + 144 + 48]);
    });

    it('rejects images, snaps and documents that cannot hold the tangram', function (): void {
        expect(fn () => tangramDefinition(width: 47, height: 200))->toThrow(InvalidArgumentException::class, 'too small');
        expect(fn () => tangramDefinition('custom', ['target' => 'square', 'snap' => 95]))->toThrow(InvalidDefinitionException::class, 'snap');
        $definition = tangramDefinition();
        expect(fn () => GameDefinition::fromArray(GameSupport::tampered($definition, ['rules' => ['unit' => 126] + $definition->rules])))
            ->toThrow(InvalidDefinitionException::class, 'square of side 4 x rules.unit');
        expect(fn () => GameDefinition::fromArray(GameSupport::tampered($definition, ['rules' => ['target' => 'swan'] + $definition->rules])))
            ->toThrow(InvalidDefinitionException::class, 'not a supported tangram target');
        expect(fn () => GameDefinition::fromArray(GameSupport::tampered($definition, ['rules' => ['snap' => 120] + $definition->rules])))
            ->toThrow(InvalidDefinitionException::class, 'smaller than rules.unit');
        $tray = Fixtures::object($definition->rules['tray']);
        expect(fn () => GameDefinition::fromArray(GameSupport::tampered($definition, ['rules' => ['tray' => ['y' => 0] + $tray] + $definition->rules])))
            ->toThrow(InvalidDefinitionException::class, 'definition.rules.tray must be');
        $tiles = Fixtures::listOf($definition->toArray()['tiles']);
        $tiles[2] = ['shape' => 'small'] + Fixtures::object($tiles[2]);
        expect(fn () => GameDefinition::fromArray(GameSupport::tampered($definition, ['tiles' => $tiles])))
            ->toThrow(InvalidDefinitionException::class, 'shape "medium"');
    });

    it('scrambles into the tray deterministically with nothing placed', function (): void {
        $definition = tangramDefinition();
        $rules = $definition->rules();
        expect($rules->scramble($definition, 11))->toBe($rules->scramble($definition, 11))
            ->and($rules->scramble($definition, 11))->not->toBe($rules->scramble($definition, 12))
            ->and($rules->progress($definition, $definition->initial))->toBe(0.0);
        $tray = Fixtures::object($definition->rules['tray']);
        foreach (Fixtures::listOf($definition->initial['pieces']) as $i => $_) {
            $pose = tangramPose($definition->initial, $i);
            expect($pose['x'])->toBeGreaterThan(Fixtures::int($tray, 'x'))
                ->and($pose['y'])->toBeGreaterThan(Fixtures::int($tray, 'y'));
        }
    });
});

describe('rules', function (): void {
    it('is complete only when every piece lies exactly on its slot', function (): void {
        $definition = tangramDefinition('hard');
        $rules = $definition->rules();
        $solved = $rules->solvedBoard($definition);
        expect($rules->isComplete($definition, $solved))->toBeTrue()->and($rules->progress($definition, $solved))->toBe(1.0);
        $off = tangramPose($solved, 3);
        $off['x']++;
        $board = tangramBoard($definition, ['small-a' => $off]);
        expect($rules->isComplete($definition, $board))->toBeFalse()
            ->and($rules->progress($definition, $board))->toBe(6 / 7);
    });

    it('never accepts an overlap: two pieces in one slot leave another slot empty', function (): void {
        $definition = tangramDefinition('custom', ['target' => 'arrow', 'snap' => 30]);
        $rules = $definition->rules();
        $solved = $rules->solvedBoard($definition);
        $board = tangramBoard($definition, ['large-b' => tangramPose($solved, 0)]);
        expect($rules->isComplete($definition, $board))->toBeFalse()
            ->and($rules->progress($definition, $board))->toBe(6 / 7);
    });

    it('accepts any same-shaped piece in a silhouette slot but only the own piece in a picture slot', function (): void {
        // A pose is relative to the piece's own outline, so each large
        // triangle needs the pose that lays it on the other's slot.
        $arrow = tangramDefinition('custom', ['target' => 'arrow', 'snap' => 30]);
        $slots = Tangram::slots('arrow', $arrow->crop->x, $arrow->crop->y, 120);
        $swapped = tangramBoard($arrow, ['large-a' => fitOn('large-a', $slots[1]), 'large-b' => fitOn('large-b', $slots[0])]);
        expect($arrow->rules()->isComplete($arrow, $swapped))->toBeTrue();
        // Solved poses swapped as numbers do not cover the slots.
        $solved = $arrow->rules()->solvedBoard($arrow);
        expect($arrow->rules()->isComplete($arrow, tangramBoard($arrow, ['large-a' => tangramPose($solved, 1), 'large-b' => tangramPose($solved, 0)])))->toBeFalse();

        $square = tangramDefinition();
        $solved = $square->rules()->solvedBoard($square);
        $swapped = tangramBoard($square, ['small-a' => tangramPose($solved, 4), 'small-b' => tangramPose($solved, 3)]);
        expect($square->rules()->isComplete($square, $swapped))->toBeFalse();
        // The square piece is symmetric, but the picture needs its own orientation.
        $turned = tangramPose($solved, 5);
        $turned['rotation'] = 2;
        expect($square->rules()->isComplete($square, tangramBoard($square, ['square' => $turned])))->toBeFalse();
    });

    it('snaps a pose within the snap distance onto the slot, keeping the side up', function (): void {
        $definition = tangramDefinition('easy');
        $rules = $definition->rules();
        $solved = $rules->solvedBoard($definition);
        $board = $definition->initial;
        $slot = tangramPose($solved, 5);

        // Near, turned a quarter (the square is symmetric): snaps to the picture pose.
        $near = ['x' => $slot['x'] + 20, 'y' => $slot['y'] - 20, 'rotation' => 2, 'flipped' => false];
        expect(tangramPose($rules->apply($definition, $board, poseAction('square', $near)), 5))->toBe($slot);
        // Beyond the snap distance (60 px at easy): stays where it was put.
        $far = ['x' => $slot['x'] + 61, 'y' => $slot['y'], 'rotation' => 0, 'flipped' => false];
        expect(tangramPose($rules->apply($definition, $board, poseAction('square', $far)), 5))->toBe($far);
        // A wrong orientation does not snap, however close.
        $tilted = ['x' => $slot['x'], 'y' => $slot['y'], 'rotation' => 1, 'flipped' => false];
        expect(tangramPose($rules->apply($definition, $board, poseAction('square', $tilted)), 5))->toBe($tilted);

        // Silhouette: a flipped piece snaps in its own flipped orientation.
        $arrow = tangramDefinition('custom', ['target' => 'arrow', 'snap' => 30]);
        $slots = Tangram::slots('arrow', $arrow->crop->x, $arrow->crop->y, 120);
        $fit = null;
        for ($rotation = 0; $rotation < 8 && ($fit['flipped'] ?? false) !== true; $rotation++) {
            $fit = Tangram::fit('large-a', $slots[1], 120, ['x' => 0, 'y' => 0, 'rotation' => $rotation, 'flipped' => true]);
        }
        expect($fit['flipped'] ?? null)->toBeTrue();
        $pose = ['x' => $slots[1]['x'] + 5, 'y' => $slots[1]['y'] + 5, 'rotation' => $fit['rotation'] ?? 0, 'flipped' => true];
        expect(tangramPose($arrow->rules()->apply($arrow, $arrow->initial, poseAction('large-a', $pose)), 0))->toBe($fit);
    });

    it('does not snap onto an occupied slot', function (): void {
        $definition = tangramDefinition('custom', ['target' => 'arrow', 'snap' => 30]);
        $rules = $definition->rules();
        $solved = $rules->solvedBoard($definition);
        $slots = Tangram::slots('arrow', $definition->crop->x, $definition->crop->y, 120);
        $board = tangramBoard($definition, ['large-a' => fitOn('large-a', $slots[1])], $definition->initial);
        $near = tangramPose($solved, 1);
        $near['x'] += 3;
        expect(tangramPose($rules->apply($definition, $board, poseAction('large-b', $near)), 1))->toBe($near);
    });

    it('rejects poses outside the play area, unchanged poses and unknown pieces', function (): void {
        $definition = tangramDefinition();
        $rules = $definition->rules();
        $board = $definition->initial;
        $area = Fixtures::object($definition->rules['area']);
        $outside = ['x' => Fixtures::int($area, 'x'), 'y' => Fixtures::int($area, 'y'), 'rotation' => 0, 'flipped' => false];
        expect(fn () => $rules->apply($definition, $board, poseAction('medium', $outside)))->toThrow(IllegalActionException::class, 'leave the play area');
        expect(fn () => $rules->apply($definition, $board, poseAction('medium', tangramPose($board, 2))))->toThrow(IllegalActionException::class, 'already in that pose');
        expect(fn () => $rules->apply($definition, $board, poseAction('kite', tangramPose($board, 2))))->toThrow(IllegalActionException::class, 'not on the board');
        expect(fn () => $rules->apply($definition, $board, ['type' => 'pose', 'piece' => 'medium', 'x' => 1, 'y' => 1, 'rotation' => 8, 'flipped' => false]))->toThrow(InvalidDefinitionException::class);
        expect(fn () => $rules->apply($definition, $board, ['type' => 'swap', 'a' => 0, 'b' => 1]))->toThrow(IllegalActionException::class, 'does not support "swap"');
        // A small nudge of a placed piece snaps back: nothing changes.
        $solved = $rules->solvedBoard($definition);
        $nudge = tangramPose($solved, 2);
        $nudge['x'] += 2;
        expect(fn () => $rules->apply($definition, $solved, poseAction('medium', $nudge)))->toThrow(IllegalActionException::class, 'already in that pose');
    });

    it('offers hints and legal actions that place unplaced pieces', function (): void {
        $definition = tangramDefinition();
        $engine = new GameEngine();
        $state = $engine->start($definition);
        expect($definition->rules()->legalActions($definition, $state->board))->toHaveCount(7);
        for ($i = 0; $i < 7; $i++) {
            $hint = $definition->rules()->hint($definition, $state->board);
            expect($hint)->not->toBeNull();
            $state = $engine->apply($definition, $state, $hint ?? []);
        }
        expect($state->completed)->toBeTrue()
            ->and($engine->hint($definition, $state))->toBeNull()
            ->and($definition->rules()->legalActions($definition, $state->board))->toBe([]);
        expect($engine->hint($definition, $engine->start($definition)))->not->toBeNull();
    });
});

describe('state', function (): void {
    it('restores a partly assembled state exactly', function (): void {
        $definition = tangramDefinition('medium', seed: 8);
        $engine = new GameEngine();
        $state = $engine->start($definition);
        foreach ([0, 1] as $_) {
            $state = $engine->apply($definition, $state, $definition->rules()->hint($definition, $state->board) ?? []);
        }
        $state = $engine->apply($definition, $state, poseAction('square', ['x' => 10, 'y' => 20, 'rotation' => 3, 'flipped' => true]));
        $restored = GameState::fromJson($definition, $state->toJson());
        expect($restored->board)->toBe($state->board)
            ->and($restored->moves)->toBe(3)
            ->and($definition->rules()->progress($definition, $restored->board))->toBe(2 / 7);
        $next = $definition->rules()->hint($definition, $state->board) ?? [];
        expect($engine->apply($definition, $restored, $next)->board)->toBe($engine->apply($definition, $state, $next)->board);
    });

    it('rejects boards with missing, malformed or out-of-area pieces', function (array $board, string $message): void {
        $definition = tangramDefinition();
        expect(fn () => $definition->rules()->validateBoard($definition, $board))->toThrow(InvalidDefinitionException::class, $message);
    })->with([
        'six pieces' => [['pieces' => array_fill(0, 6, ['x' => 300, 'y' => 300, 'rotation' => 0, 'flipped' => false])], 'one entry per piece'],
        'bad rotation' => [['pieces' => array_fill(0, 7, ['x' => 300, 'y' => 300, 'rotation' => 9, 'flipped' => false])], 'rotation'],
        'far away' => [['pieces' => array_fill(0, 7, ['x' => 99999, 'y' => 300, 'rotation' => 0, 'flipped' => false])], 'outside rules.area'],
        'extra key' => [['pieces' => array_fill(0, 7, ['x' => 300, 'y' => 300, 'rotation' => 0, 'flipped' => false, 'z' => 1])], 'z'],
    ]);

    it('is registered with undo, redo and hints', function (): void {
        $definition = tangramDefinition();
        expect($definition->toArray()['capabilities'])->toBe(['undo' => true, 'redo' => true, 'reset' => true, 'shuffle' => true, 'hint' => true])
            ->and($definition->toArray()['actions'] ?? null)->toBe(['pose'])
            ->and(TangramRules::path([[1, 2], [3, 4], [5, 6]]))->toBe('M 1 2 L 3 4 L 5 6 Z');
    });
});
