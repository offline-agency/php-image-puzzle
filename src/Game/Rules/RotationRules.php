<?php

declare(strict_types=1);

namespace ImagePuzzle\Game\Rules;

use ImagePuzzle\Exception\IllegalActionException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\Doc;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\Prng;

/**
 * Rotation puzzle: tiles stay in their cells and turn.
 *
 * Orientation convention (shared by PHP, state, renderers and validators):
 * the value is the clockwise rotation in degrees applied to the tile image
 * when it is displayed, so 0 is upright and solved means every value is 0.
 * "cw" moves to the next larger allowed angle, "ccw" to the next smaller one,
 * wrapping around. Quarter turns require square cells.
 *
 * Board: {"orientations": [degrees, ...]} indexed like definition.tiles.
 */
final class RotationRules extends GridRules
{
    public const MAX_SIDE = 16;
    public const ANGLES = [0, 90, 180, 270];

    public function kind(): GameKind
    {
        return GameKind::Rotation;
    }

    public function actionTypes(): array
    {
        return ['rotate'];
    }

    public function capabilities(): array
    {
        return ['undo' => true, 'redo' => true, 'reset' => true, 'shuffle' => true, 'hint' => true];
    }

    public function validateDefinition(GameDefinition $definition): void
    {
        $this->validateGrid($definition, 1, 1, self::MAX_SIDE);
        Doc::keys($definition->rules, ['orientations'], 'definition.rules');
        $allowed = self::allowed($definition);
        [$w, $h] = self::cellSize($definition);
        if ($w !== $h && (in_array(90, $allowed, true) || in_array(270, $allowed, true))) {
            throw new InvalidDefinitionException("Quarter turns need square cells; cells are {$w}x{$h}.");
        }
    }

    public function solvedBoard(GameDefinition $definition): array
    {
        return ['orientations' => array_fill(0, count($definition->tiles), 0)];
    }

    public function scramble(GameDefinition $definition, int $seed): array
    {
        $rng = new Prng($seed, 'image-puzzle/scramble/rotation');
        $allowed = self::allowed($definition);
        $orientations = [];
        foreach ($definition->tiles as $_) {
            $orientations[] = $allowed[$rng->int(count($allowed))];
        }
        if (array_sum($orientations) === 0) {
            $orientations[0] = $allowed[1];
        }

        return ['orientations' => $orientations];
    }

    public function validateBoard(GameDefinition $definition, mixed $board): array
    {
        $board = Doc::object($board, 'board');
        Doc::keys($board, ['orientations'], 'board');
        $list = Doc::list($board['orientations'] ?? null, 'board.orientations', GameDefinition::MAX_TILES);
        if (count($list) !== count($definition->tiles)) {
            throw new InvalidDefinitionException('board.orientations must have one entry per tile.');
        }
        $allowed = self::allowed($definition);
        $out = [];
        foreach ($list as $i => $value) {
            $angle = Doc::intValue($value, "board.orientations[{$i}]");
            if (!in_array($angle, $allowed, true)) {
                throw new InvalidDefinitionException("board.orientations[{$i}] = {$angle} is not an allowed orientation.");
            }
            $out[] = $angle;
        }

        return ['orientations' => $out];
    }

    public function apply(GameDefinition $definition, array $board, array $action): array
    {
        $this->actionOf($action, ['tile', 'direction']);
        $tile = Doc::id($action['tile'] ?? null, 'action.tile');
        $direction = Doc::string($action, 'direction', 'action', 8);
        if ($direction !== 'cw' && $direction !== 'ccw') {
            throw new InvalidDefinitionException('action.direction must be "cw" or "ccw".');
        }
        $index = self::index($definition, $tile);
        $orientations = self::orientations($board);
        $allowed = self::allowed($definition);
        $k = array_search($orientations[$index] ?? null, $allowed, true);
        if (!is_int($k)) {
            throw new InvalidDefinitionException("board.orientations[{$index}] is not an allowed orientation.");
        }
        $n = count($allowed);
        $orientations[$index] = $allowed[($k + ($direction === 'cw' ? 1 : $n - 1)) % $n];

        return ['orientations' => $orientations];
    }

    public function legalActions(GameDefinition $definition, array $board): array
    {
        $twoWay = count(self::allowed($definition)) > 2;
        $out = [];
        foreach ($definition->tiles as $tile) {
            $out[] = ['type' => 'rotate', 'tile' => $tile->id, 'direction' => 'cw'];
            if ($twoWay) {
                $out[] = ['type' => 'rotate', 'tile' => $tile->id, 'direction' => 'ccw'];
            }
        }

        return $out;
    }

    public function isComplete(GameDefinition $definition, array $board): bool
    {
        return array_sum(self::orientations($board)) === 0;
    }

    public function progress(GameDefinition $definition, array $board): float
    {
        $orientations = self::orientations($board);

        return count(array_filter($orientations, static fn (int $angle): bool => $angle === 0)) / max(1, count($orientations));
    }

    /**
     * Turns the first misaligned tile the shorter way (clockwise on a tie).
     */
    public function hint(GameDefinition $definition, array $board): ?array
    {
        $allowed = self::allowed($definition);
        $n = count($allowed);
        foreach (self::orientations($board) as $i => $angle) {
            if ($angle !== 0) {
                $k = (int) array_search($angle, $allowed, true);

                return ['type' => 'rotate', 'tile' => $definition->tiles[$i]->id, 'direction' => $k < $n - $k ? 'ccw' : 'cw'];
            }
        }

        return null;
    }

    /**
     * @return list<int> ascending, starts with 0, at least two entries
     */
    public static function allowed(GameDefinition $definition): array
    {
        $list = Doc::list($definition->rules['orientations'] ?? null, 'definition.rules.orientations', 4);
        $angles = [];
        foreach ($list as $i => $value) {
            $angles[] = Doc::intValue($value, "definition.rules.orientations[{$i}]");
        }
        $sorted = $angles;
        sort($sorted);
        if ($angles !== $sorted || count(array_unique($angles)) !== count($angles) || array_diff($angles, self::ANGLES) !== [] || count($angles) < 2 || $angles[0] !== 0) {
            throw new InvalidDefinitionException('definition.rules.orientations must be an ascending subset of [0, 90, 180, 270] with 0 and at least two angles.');
        }

        return $angles;
    }

    /**
     * @param array<string, mixed> $board
     * @return list<int>
     */
    private static function orientations(array $board): array
    {
        $out = [];
        foreach (Doc::list($board['orientations'] ?? null, 'board.orientations', GameDefinition::MAX_TILES) as $value) {
            $out[] = Doc::intValue($value, 'board.orientations[]');
        }

        return $out;
    }

    private static function index(GameDefinition $definition, string $tile): int
    {
        foreach ($definition->tiles as $i => $candidate) {
            if ($candidate->id === $tile) {
                return $i;
            }
        }

        throw new IllegalActionException("Tile \"{$tile}\" is not on the board.");
    }
}
