<?php

declare(strict_types=1);

namespace ImagePuzzle\Game\Rules;

use ImagePuzzle\Exception\IllegalActionException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\Doc;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\Prng;
use ImagePuzzle\Game\Tile;
use ImagePuzzle\Geometry\Bounds;

/**
 * Jigsaw: interlocking pieces exported by PHP (any topology) are placed
 * freely on a world plane and snap to their solved position.
 *
 * World coordinates are source pixels: the board occupies board.crop, the
 * tray (rules.tray) lies to its right and rules.area bounds every piece box.
 * Tiles carry the manifest geometry: "rendered" (solved box of the piece
 * image), "image" (relative file) and optionally "path" (outline).
 *
 * Board: {"pieces": [{"x", "y", "placed"}, ...]} indexed like
 * definition.tiles; x/y is the top-left of the piece box. A "place" whose
 * box lands within rules.snap pixels (both axes) of the solved box snaps and
 * locks the piece. Other positions inside the area are legal moves.
 */
final class JigsawRules implements ModeRules
{
    public const MAX_SNAP = 256;
    public const PATH_PATTERN = '/^M[-0-9. LZ]+$/';

    public function kind(): GameKind
    {
        return GameKind::Jigsaw;
    }

    public function actionTypes(): array
    {
        return ['place'];
    }

    public function capabilities(): array
    {
        return ['undo' => true, 'redo' => true, 'reset' => true, 'shuffle' => true, 'hint' => true];
    }

    public function validateDefinition(GameDefinition $definition): void
    {
        Doc::keys($definition->rules, ['topology', 'snap', 'area', 'tray'], 'definition.rules');
        $topology = Doc::string($definition->rules, 'topology', 'definition.rules', 16);
        if (!in_array($topology, ['rect', 'hex', 'triangle'], true)) {
            throw new InvalidDefinitionException("Unknown jigsaw topology \"{$topology}\".");
        }
        $snap = self::snap($definition);
        $area = self::area($definition);
        $tray = self::tray($definition);
        if (!$area->contains($tray) || !$area->contains($definition->crop)) {
            throw new InvalidDefinitionException('definition.rules.area must contain the board and the tray.');
        }
        if ($tray->x <= $definition->crop->x + $definition->crop->width + $snap - 1) {
            throw new InvalidDefinitionException('definition.rules.tray must start more than rules.snap pixels right of the board.');
        }
        if ($definition->tiles === []) {
            throw new InvalidDefinitionException('A jigsaw needs at least one piece.');
        }
        foreach ($definition->tiles as $index => $tile) {
            if ($tile->home !== $index) {
                throw new InvalidDefinitionException("Tile {$tile->id} must have home {$index}.");
            }
            Doc::keys($tile->attributes, ['rendered', 'image', 'path'], "definition.tiles[{$index}]");
            $rendered = self::rendered($tile);
            if (!$definition->crop->contains($rendered) || !$rendered->contains($tile->rect)) {
                throw new InvalidDefinitionException("Tile {$tile->id}: rendered box must contain its cell and lie on the board.");
            }
            if ($rendered->width > $tray->width || $rendered->height > $tray->height) {
                throw new InvalidDefinitionException("Tile {$tile->id} does not fit in the tray.");
            }
            $image = Doc::stringValue($tile->attribute('image'), "definition.tiles[{$index}].image", 255);
            if (preg_match('~^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$~', $image) !== 1) {
                throw new InvalidDefinitionException("definition.tiles[{$index}].image must be a safe relative path.");
            }
            if (array_key_exists('path', $tile->attributes)) {
                $path = Doc::stringValue($tile->attributes['path'], "definition.tiles[{$index}].path", 1 << 20);
                if (preg_match(self::PATH_PATTERN, $path) !== 1) {
                    throw new InvalidDefinitionException("definition.tiles[{$index}].path must be M/L/Z path data.");
                }
            }
        }
    }

    public function solvedBoard(GameDefinition $definition): array
    {
        $pieces = [];
        foreach ($definition->tiles as $tile) {
            $rendered = self::rendered($tile);
            $pieces[] = ['x' => $rendered->x, 'y' => $rendered->y, 'placed' => true];
        }

        return ['pieces' => $pieces];
    }

    /**
     * Every piece box at a uniformly drawn position inside the tray (x then
     * y per piece, in tile order); the tray never overlaps a snap zone.
     */
    public function scramble(GameDefinition $definition, int $seed): array
    {
        $rng = new Prng($seed, 'image-puzzle/scramble/jigsaw');
        $tray = self::tray($definition);
        $pieces = [];
        foreach ($definition->tiles as $tile) {
            $rendered = self::rendered($tile);
            $x = $tray->x + $rng->int($tray->width - $rendered->width + 1);
            $y = $tray->y + $rng->int($tray->height - $rendered->height + 1);
            $pieces[] = ['x' => $x, 'y' => $y, 'placed' => false];
        }

        return ['pieces' => $pieces];
    }

    public function validateBoard(GameDefinition $definition, mixed $board): array
    {
        $board = Doc::object($board, 'board');
        Doc::keys($board, ['pieces'], 'board');
        $list = Doc::list($board['pieces'] ?? null, 'board.pieces', GameDefinition::MAX_TILES);
        if (count($list) !== count($definition->tiles)) {
            throw new InvalidDefinitionException('board.pieces must have one entry per tile.');
        }
        $snap = self::snap($definition);
        $out = [];
        foreach ($list as $i => $value) {
            $piece = Doc::object($value, "board.pieces[{$i}]");
            Doc::keys($piece, ['x', 'y', 'placed'], "board.pieces[{$i}]");
            $x = Doc::int($piece, 'x', "board.pieces[{$i}]", -(1 << 20), 1 << 20);
            $y = Doc::int($piece, 'y', "board.pieces[{$i}]", -(1 << 20), 1 << 20);
            $placed = Doc::bool($piece, 'placed', "board.pieces[{$i}]");
            $rendered = self::rendered($definition->tiles[$i]);
            if ($placed && ($x !== $rendered->x || $y !== $rendered->y)) {
                throw new InvalidDefinitionException("board.pieces[{$i}] is marked placed away from its solved position.");
            }
            if (!$placed && abs($x - $rendered->x) <= $snap && abs($y - $rendered->y) <= $snap) {
                throw new InvalidDefinitionException("board.pieces[{$i}] lies inside its snap zone but is not placed.");
            }
            if (!self::area($definition)->contains(new Bounds($x, $y, $rendered->width, $rendered->height))) {
                throw new InvalidDefinitionException("board.pieces[{$i}] lies outside rules.area.");
            }
            $out[] = ['x' => $x, 'y' => $y, 'placed' => $placed];
        }

        return ['pieces' => $out];
    }

    public function apply(GameDefinition $definition, array $board, array $action): array
    {
        $type = Doc::string($action, 'type', 'action', 32);
        if ($type !== 'place') {
            throw new IllegalActionException("The jigsaw mode does not support \"{$type}\" actions.");
        }
        Doc::keys($action, ['type', 'piece', 'x', 'y'], 'action');
        $id = Doc::id($action['piece'] ?? null, 'action.piece');
        $x = Doc::int($action, 'x', 'action', -(1 << 20), 1 << 20);
        $y = Doc::int($action, 'y', 'action', -(1 << 20), 1 << 20);
        $index = null;
        foreach ($definition->tiles as $i => $tile) {
            if ($tile->id === $id) {
                $index = $i;
            }
        }
        if ($index === null) {
            throw new IllegalActionException("Piece \"{$id}\" is not on the board.");
        }
        $pieces = self::pieces($board);
        if ($pieces[$index]['placed']) {
            throw new IllegalActionException("Piece \"{$id}\" is already placed.");
        }
        $rendered = self::rendered($definition->tiles[$index]);
        $snap = self::snap($definition);
        // Snapping wins over the area check so edge pieces dropped slightly
        // off the board still lock into place.
        if (abs($x - $rendered->x) <= $snap && abs($y - $rendered->y) <= $snap) {
            $pieces[$index] = ['x' => $rendered->x, 'y' => $rendered->y, 'placed' => true];

            return ['pieces' => $pieces];
        }
        if (!self::area($definition)->contains(new Bounds($x, $y, $rendered->width, $rendered->height))) {
            throw new IllegalActionException("Piece \"{$id}\" cannot leave the play area.");
        }
        $pieces[$index] = ['x' => $x, 'y' => $y, 'placed' => false];

        return ['pieces' => $pieces];
    }

    /**
     * The canonical (solving) placement of every unplaced piece; any other
     * in-area position of an unplaced piece is also legal.
     */
    public function legalActions(GameDefinition $definition, array $board): array
    {
        $out = [];
        foreach (self::pieces($board) as $i => $piece) {
            if (!$piece['placed']) {
                $rendered = self::rendered($definition->tiles[$i]);
                $out[] = ['type' => 'place', 'piece' => $definition->tiles[$i]->id, 'x' => $rendered->x, 'y' => $rendered->y];
            }
        }

        return $out;
    }

    public function isComplete(GameDefinition $definition, array $board): bool
    {
        foreach (self::pieces($board) as $piece) {
            if (!$piece['placed']) {
                return false;
            }
        }

        return true;
    }

    public function progress(GameDefinition $definition, array $board): float
    {
        $pieces = self::pieces($board);

        return count(array_filter($pieces, static fn (array $piece): bool => $piece['placed'])) / max(1, count($pieces));
    }

    public function hint(GameDefinition $definition, array $board): ?array
    {
        return $this->legalActions($definition, $board)[0] ?? null;
    }

    public static function rendered(Tile $tile): Bounds
    {
        return Tile::rect($tile->attribute('rendered'), "tile {$tile->id} rendered");
    }

    public static function snap(GameDefinition $definition): int
    {
        return Doc::int($definition->rules, 'snap', 'definition.rules', 1, self::MAX_SNAP);
    }

    public static function area(GameDefinition $definition): Bounds
    {
        return Tile::rect($definition->rules['area'] ?? null, 'definition.rules.area');
    }

    public static function tray(GameDefinition $definition): Bounds
    {
        return Tile::rect($definition->rules['tray'] ?? null, 'definition.rules.tray');
    }

    /**
     * @param array<string, mixed> $board
     * @return list<array{x: int, y: int, placed: bool}>
     */
    private static function pieces(array $board): array
    {
        $out = [];
        foreach (Doc::list($board['pieces'] ?? null, 'board.pieces', GameDefinition::MAX_TILES) as $i => $value) {
            $piece = Doc::object($value, "board.pieces[{$i}]");
            $out[] = [
                'x' => Doc::int($piece, 'x', "board.pieces[{$i}]"),
                'y' => Doc::int($piece, 'y', "board.pieces[{$i}]"),
                'placed' => Doc::bool($piece, 'placed', "board.pieces[{$i}]"),
            ];
        }

        return $out;
    }
}
