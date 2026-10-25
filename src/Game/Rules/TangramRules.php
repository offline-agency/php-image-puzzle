<?php

declare(strict_types=1);

namespace ImagePuzzle\Game\Rules;

use ImagePuzzle\Exception\IllegalActionException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\Doc;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\Prng;
use ImagePuzzle\Game\Tangram\Tangram;
use ImagePuzzle\Geometry\Bounds;

/**
 * Tangram: the seven standard pieces cut from a square image (see Tangram
 * for the partition and the pose convention) are arranged into a target.
 *
 * Definition: board.crop is the square (side 4 x rules.unit, unit a multiple
 * of 6), board.rows = board.cols = 4. Tiles are the seven pieces in Tangram
 * order with home = index, rect = the solved bounding box and attributes
 * shape and path (the solved outline, so SVG and PDF exports cut them).
 * rules: target (a name from Tangram::TARGETS), unit, snap (pixels), area
 * and tray (world rectangles derived from the target and unit).
 *
 * Board: {"pieces": [{"x", "y", "rotation", "flipped"}, ...]} in tile
 * order: the pose of each piece. Action {"type": "pose", "piece", "x", "y",
 * "rotation", "flipped"} moves, turns and/or flips one piece; the piece must
 * stay inside rules.area. When every vertex of the posed piece lies within
 * rules.snap pixels of a free slot it fits, the piece snaps exactly onto
 * that slot.
 *
 * Completion: every piece lies exactly (to 1e-6 px) on a distinct slot it
 * fits. Picture slots ("square") take only their own piece in its solved
 * orientation; silhouette slots take any piece of the same shape in any
 * orientation. Other ways of filling a silhouette with the seven pieces are
 * not recognised, and overlaps or gaps can never complete: slots are a
 * tiling validated by Tangram::validate() (no overlap, total area 16 units).
 */
final class TangramRules implements ModeRules
{
    public const MIN_UNIT = 12;
    public const MAX_SNAP = 256;
    /** Tray: 4 x 2 cells of TRAY_CELL units. */
    public const TRAY_COLS = 4;
    public const TRAY_ROWS = 2;
    public const TRAY_CELL = 3;

    public function kind(): GameKind
    {
        return GameKind::Tangram;
    }

    public function actionTypes(): array
    {
        return ['pose'];
    }

    public function capabilities(): array
    {
        return ['undo' => true, 'redo' => true, 'reset' => true, 'shuffle' => true, 'hint' => true];
    }

    /**
     * Area and tray of a target, in world pixels.
     *
     * @return array{area: Bounds, tray: Bounds}
     */
    public static function layout(string $target, Bounds $crop, int $unit): array
    {
        [$minX, $minY, $maxX, $maxY] = Tangram::extent($target);
        $centre = $crop->x + intdiv(($minX + $maxX) * $unit, 2);
        $trayWidth = self::TRAY_COLS * self::TRAY_CELL * $unit;
        $tray = new Bounds($centre - intdiv($trayWidth, 2), $crop->y + ($maxY + 1) * $unit, Doc::positiveValue($trayWidth, 'tray width'), Doc::positiveValue(self::TRAY_ROWS * self::TRAY_CELL * $unit, 'tray height'));
        $left = min($crop->x + $minX * $unit, $tray->x) - $unit;
        $top = $crop->y + $minY * $unit - $unit;
        $right = max($crop->x + $maxX * $unit, $tray->x + $tray->width) + $unit;
        $bottom = $tray->y + $tray->height + $unit;

        return ['area' => new Bounds($left, $top, Doc::positiveValue($right - $left, 'area width'), Doc::positiveValue($bottom - $top, 'area height')), 'tray' => $tray];
    }

    public function validateDefinition(GameDefinition $definition): void
    {
        $rules = $definition->rules;
        Doc::keys($rules, ['target', 'unit', 'snap', 'area', 'tray'], 'definition.rules');
        $target = Doc::string($rules, 'target', 'definition.rules', 32);
        if (!isset(Tangram::TARGETS[$target])) {
            throw new InvalidDefinitionException("definition.rules.target \"{$target}\" is not a supported tangram target (" . implode(', ', array_keys(Tangram::TARGETS)) . ').');
        }
        $unit = Doc::int($rules, 'unit', 'definition.rules', self::MIN_UNIT, 1 << 16);
        if ($unit % 6 !== 0) {
            throw new InvalidDefinitionException('definition.rules.unit must be a multiple of 6.');
        }
        $snap = Doc::int($rules, 'snap', 'definition.rules', 1, self::MAX_SNAP);
        if ($snap >= $unit) {
            throw new InvalidDefinitionException('definition.rules.snap must be smaller than rules.unit.');
        }
        $crop = $definition->crop;
        if ($definition->rows !== 4 || $definition->cols !== 4 || $crop->width !== 4 * $unit || $crop->height !== 4 * $unit) {
            throw new InvalidDefinitionException('A tangram board is 4x4 units: board.crop must be a square of side 4 x rules.unit.');
        }
        $layout = self::layout($target, $crop, $unit);
        foreach (['area', 'tray'] as $key) {
            $rect = Doc::object($rules[$key] ?? null, "definition.rules.{$key}");
            if ($rect !== $layout[$key]->toArray()) {
                throw new InvalidDefinitionException("definition.rules.{$key} must be " . json_encode($layout[$key]->toArray()) . ' for this target and unit.');
            }
        }
        $ids = Tangram::ids();
        if (count($definition->tiles) !== count($ids)) {
            throw new InvalidDefinitionException('A tangram has exactly ' . count($ids) . ' pieces.');
        }
        foreach ($definition->tiles as $index => $tile) {
            $id = $ids[$index];
            $home = Tangram::home($id, $crop->x, $crop->y, $unit);
            $box = Tangram::homeBox($id, $crop->x, $crop->y, $unit);
            if ($tile->id !== $id || $tile->home !== $index || [$tile->rect->x, $tile->rect->y, $tile->rect->width, $tile->rect->height] !== $box) {
                throw new InvalidDefinitionException("Piece {$index} must be \"{$id}\" with home {$index} and its solved bounding box.");
            }
            Doc::keys($tile->attributes, ['shape', 'path'], "definition.tiles[{$index}]");
            if (($tile->attributes['shape'] ?? null) !== Tangram::PIECES[$id]['shape'] || ($tile->attributes['path'] ?? null) !== self::path($home)) {
                throw new InvalidDefinitionException("definition.tiles[{$index}] must have shape \"" . Tangram::PIECES[$id]['shape'] . '" and the solved outline as path.');
            }
        }
    }

    /**
     * SVG path data of a polygon with integer vertices.
     *
     * @param list<array{0: int, 1: int}> $vertices
     */
    public static function path(array $vertices): string
    {
        $parts = [];
        foreach ($vertices as $i => [$x, $y]) {
            $parts[] = ($i === 0 ? 'M ' : 'L ') . $x . ' ' . $y;
        }

        return implode(' ', $parts) . ' Z';
    }

    public function solvedBoard(GameDefinition $definition): array
    {
        $pieces = [];
        foreach (self::slotsOf($definition) as $slot) {
            $fit = Tangram::fit($slot['piece'], $slot, self::unit($definition));
            if ($fit === null) {
                throw new InvalidDefinitionException('The tangram target does not fit its pieces.');
            }
            $pieces[] = $fit;
        }

        return ['pieces' => $pieces];
    }

    /**
     * Each piece at the centre of a tray cell (cells drawn without
     * repetition), with a random rotation and side.
     */
    public function scramble(GameDefinition $definition, int $seed): array
    {
        $rng = new Prng($seed, 'image-puzzle/scramble/tangram');
        $tray = self::bounds($definition, 'tray');
        $unit = self::unit($definition);
        $cells = $rng->shuffle(range(0, self::TRAY_COLS * self::TRAY_ROWS - 1));
        $half = intdiv(self::TRAY_CELL * $unit, 2);
        $pieces = [];
        foreach ($definition->tiles as $i => $tile) {
            $cell = $cells[$i];
            $pieces[] = [
                'x' => $tray->x + ($cell % self::TRAY_COLS) * self::TRAY_CELL * $unit + $half,
                'y' => $tray->y + intdiv($cell, self::TRAY_COLS) * self::TRAY_CELL * $unit + $half,
                'rotation' => $rng->int(8),
                'flipped' => $rng->int(2) === 1,
            ];
        }

        return ['pieces' => $pieces];
    }

    public function validateBoard(GameDefinition $definition, mixed $board): array
    {
        $board = Doc::object($board, 'board');
        Doc::keys($board, ['pieces'], 'board');
        $list = Doc::list($board['pieces'] ?? null, 'board.pieces', count($definition->tiles));
        if (count($list) !== count($definition->tiles)) {
            throw new InvalidDefinitionException('board.pieces must have one entry per piece.');
        }
        $pieces = [];
        foreach ($list as $i => $value) {
            $pose = self::pose($value, "board.pieces[{$i}]");
            if (!self::inside($definition, $definition->tiles[$i]->id, $pose)) {
                throw new InvalidDefinitionException("board.pieces[{$i}] lies outside rules.area.");
            }
            $pieces[] = $pose;
        }

        return ['pieces' => $pieces];
    }

    public function apply(GameDefinition $definition, array $board, array $action): array
    {
        $type = Doc::string($action, 'type', 'action', 32);
        if ($type !== 'pose') {
            throw new IllegalActionException("The tangram mode does not support \"{$type}\" actions.");
        }
        Doc::keys($action, ['type', 'piece', 'x', 'y', 'rotation', 'flipped'], 'action');
        $id = Doc::id($action['piece'] ?? null, 'action.piece');
        $index = array_search($id, Tangram::ids(), true);
        if ($index === false) {
            throw new IllegalActionException("Piece \"{$id}\" is not on the board.");
        }
        $pose = self::pose(['x' => $action['x'] ?? null, 'y' => $action['y'] ?? null, 'rotation' => $action['rotation'] ?? null, 'flipped' => $action['flipped'] ?? null], 'action');
        $pieces = self::pieces($board);
        if (!self::inside($definition, $id, $pose)) {
            throw new IllegalActionException("Piece \"{$id}\" would leave the play area.");
        }
        $pose = self::snapped($definition, $pieces, $index, $pose);
        if ($pieces[$index] === $pose) {
            throw new IllegalActionException("Piece \"{$id}\" is already in that pose.");
        }
        $pieces[$index] = $pose;

        return ['pieces' => $pieces];
    }

    /**
     * Poses that put an unplaced piece on a free slot it fits, piece by piece.
     */
    public function legalActions(GameDefinition $definition, array $board): array
    {
        $pieces = self::pieces($board);
        $assignment = self::assignment($definition, $pieces);
        $free = array_diff_key(self::slotsOf($definition), array_flip($assignment));
        $unit = self::unit($definition);
        $out = [];
        foreach (Tangram::ids() as $index => $id) {
            if (isset($assignment[$index])) {
                continue;
            }
            foreach ($free as $slot) {
                $fit = Tangram::fit($id, $slot, $unit);
                if ($fit !== null && $fit !== $pieces[$index]) {
                    $out[] = ['type' => 'pose', 'piece' => $id] + $fit;
                }
            }
        }

        return $out;
    }

    public function isComplete(GameDefinition $definition, array $board): bool
    {
        return count(self::assignment($definition, self::pieces($board))) === count(Tangram::ids());
    }

    public function progress(GameDefinition $definition, array $board): float
    {
        return count(self::assignment($definition, self::pieces($board))) / count(Tangram::ids());
    }

    /** Puts the first unplaced piece on a free slot it fits (its own slot first). */
    public function hint(GameDefinition $definition, array $board): ?array
    {
        $pieces = self::pieces($board);
        $assignment = self::assignment($definition, $pieces);
        $slots = self::slotsOf($definition);
        $unit = self::unit($definition);
        foreach (Tangram::ids() as $index => $id) {
            if (isset($assignment[$index])) {
                continue;
            }
            $order = [$index, ...array_keys($slots)];
            foreach ($order as $s) {
                if (in_array($s, $assignment, true)) {
                    continue;
                }
                $fit = Tangram::fit($id, $slots[$s], $unit);
                if ($fit !== null) {
                    return ['type' => 'pose', 'piece' => $id] + $fit;
                }
            }
        }

        return null;
    }

    /**
     * Slot index of each piece that lies exactly on a slot it fits, greedily
     * in tile order (slots never overlap, so a piece matches at most one).
     *
     * @param list<array{x: int, y: int, rotation: int, flipped: bool}> $pieces
     * @return array<int, int> piece index => slot index
     */
    public static function assignment(GameDefinition $definition, array $pieces): array
    {
        $unit = self::unit($definition);
        $slots = self::slotsOf($definition);
        $assignment = [];
        foreach (Tangram::ids() as $index => $id) {
            $pose = $pieces[$index];
            $polygon = Tangram::polygon($id, $unit, $pose['x'], $pose['y'], $pose['rotation'], $pose['flipped']);
            foreach ($slots as $s => $slot) {
                if (in_array($s, $assignment, true) || Tangram::PIECES[$id]['shape'] !== $slot['shape']) {
                    continue;
                }
                if ($slot['bound']) {
                    $matches = $slot['piece'] === $id && $pose === ['x' => $slot['x'], 'y' => $slot['y'], 'rotation' => $slot['rotation'], 'flipped' => $slot['flipped']];
                } else {
                    $matches = Tangram::distance($polygon, Tangram::polygon($slot['piece'], $unit, $slot['x'], $slot['y'], $slot['rotation'], $slot['flipped'])) <= Tangram::EPSILON;
                }
                if ($matches) {
                    $assignment[$index] = $s;
                    break;
                }
            }
        }

        return $assignment;
    }

    /**
     * @param list<array{x: int, y: int, rotation: int, flipped: bool}> $pieces
     * @param array{x: int, y: int, rotation: int, flipped: bool} $pose
     * @return array{x: int, y: int, rotation: int, flipped: bool}
     */
    private static function snapped(GameDefinition $definition, array $pieces, int $index, array $pose): array
    {
        $id = Tangram::ids()[$index];
        $unit = self::unit($definition);
        $snap = Doc::int($definition->rules, 'snap', 'definition.rules', 1, self::MAX_SNAP);
        $taken = self::assignment($definition, $pieces);
        unset($taken[$index]);
        $polygon = Tangram::polygon($id, $unit, $pose['x'], $pose['y'], $pose['rotation'], $pose['flipped']);
        $best = null;
        $bestDistance = INF;
        foreach (self::slotsOf($definition) as $s => $slot) {
            if (in_array($s, $taken, true)) {
                continue;
            }
            $fit = Tangram::fit($id, $slot, $unit, $pose);
            if ($fit === null) {
                continue;
            }
            $distance = Tangram::distance($polygon, Tangram::polygon($id, $unit, $fit['x'], $fit['y'], $fit['rotation'], $fit['flipped']));
            if ($distance <= $snap && $distance < $bestDistance) {
                $best = $fit;
                $bestDistance = $distance;
            }
        }

        return $best ?? $pose;
    }

    /**
     * @param array{x: int, y: int, rotation: int, flipped: bool} $pose
     */
    private static function inside(GameDefinition $definition, string $id, array $pose): bool
    {
        $area = self::bounds($definition, 'area');
        foreach (Tangram::polygon($id, self::unit($definition), $pose['x'], $pose['y'], $pose['rotation'], $pose['flipped']) as [$x, $y]) {
            if ($x < $area->x - Tangram::EPSILON || $y < $area->y - Tangram::EPSILON || $x > $area->x + $area->width + Tangram::EPSILON || $y > $area->y + $area->height + Tangram::EPSILON) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{x: int, y: int, rotation: int, flipped: bool}
     */
    private static function pose(mixed $value, string $path): array
    {
        $pose = Doc::object($value, $path);
        Doc::keys($pose, ['x', 'y', 'rotation', 'flipped'], $path);

        return [
            'x' => Doc::int($pose, 'x', $path, -(1 << 20), 1 << 20),
            'y' => Doc::int($pose, 'y', $path, -(1 << 20), 1 << 20),
            'rotation' => Doc::int($pose, 'rotation', $path, 0, 7),
            'flipped' => Doc::bool($pose, 'flipped', $path),
        ];
    }

    /**
     * @param array<string, mixed> $board
     * @return list<array{x: int, y: int, rotation: int, flipped: bool}>
     */
    private static function pieces(array $board): array
    {
        $out = [];
        foreach (Doc::list($board['pieces'] ?? null, 'board.pieces', 7) as $i => $value) {
            $out[] = self::pose($value, "board.pieces[{$i}]");
        }

        return $out;
    }

    /**
     * @return list<array{piece: string, shape: string, bound: bool, x: int, y: int, rotation: int, flipped: bool}>
     */
    private static function slotsOf(GameDefinition $definition): array
    {
        return Tangram::slots(Doc::string($definition->rules, 'target', 'definition.rules', 32), $definition->crop->x, $definition->crop->y, self::unit($definition));
    }

    private static function unit(GameDefinition $definition): int
    {
        return Doc::int($definition->rules, 'unit', 'definition.rules', self::MIN_UNIT, 1 << 16);
    }

    private static function bounds(GameDefinition $definition, string $key): Bounds
    {
        $rect = Doc::object($definition->rules[$key] ?? null, "definition.rules.{$key}");

        return new Bounds(Doc::int($rect, 'x', $key), Doc::int($rect, 'y', $key), Doc::positive($rect, 'width', $key), Doc::positive($rect, 'height', $key));
    }
}
