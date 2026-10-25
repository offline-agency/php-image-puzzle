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
 * Strips puzzle: the image is cut into equal horizontal strips (stacked,
 * board rows = count, cols = 1) or vertical strips (side by side, rows = 1,
 * cols = count) that are reordered.
 *
 * Remainder handling: the generator crops the remainder pixels that do not
 * divide evenly (centred, recorded in board.crop) so every strip has the same
 * explicit size.
 *
 * Board: {"order": [stripId, ...]} top-to-bottom or left-to-right. A "move"
 * removes the strip from its position and inserts it at index "to".
 */
final class StripsRules extends GridRules
{
    public const MAX_STRIPS = 128;

    public function kind(): GameKind
    {
        return GameKind::Strips;
    }

    public function actionTypes(): array
    {
        return ['move'];
    }

    public function capabilities(): array
    {
        return ['undo' => true, 'redo' => true, 'reset' => true, 'shuffle' => true, 'hint' => true];
    }

    public function validateDefinition(GameDefinition $definition): void
    {
        Doc::keys($definition->rules, ['axis'], 'definition.rules');
        $axis = self::axis($definition);
        if ($axis === 'horizontal' && $definition->cols !== 1) {
            throw new InvalidDefinitionException('Horizontal strips need board.cols = 1.');
        }
        if ($axis === 'vertical' && $definition->rows !== 1) {
            throw new InvalidDefinitionException('Vertical strips need board.rows = 1.');
        }
        $this->validateGrid($definition, 1, 1, self::MAX_STRIPS);
    }

    public function solvedBoard(GameDefinition $definition): array
    {
        return ['order' => self::tileIds($definition)];
    }

    public function scramble(GameDefinition $definition, int $seed): array
    {
        return ['order' => self::shuffledPermutation(self::tileIds($definition), new Prng($seed, 'image-puzzle/scramble/strips'))];
    }

    public function validateBoard(GameDefinition $definition, mixed $board): array
    {
        $board = Doc::object($board, 'board');
        Doc::keys($board, ['order'], 'board');

        return ['order' => self::permutation($board['order'] ?? null, self::tileIds($definition), 'board.order')];
    }

    public function apply(GameDefinition $definition, array $board, array $action): array
    {
        $this->actionOf($action, ['strip', 'to']);
        $strip = Doc::id($action['strip'] ?? null, 'action.strip');
        $order = self::stringList($board, 'order');
        $to = Doc::int($action, 'to', 'action', 0, max(0, count($order) - 1));
        $from = array_search($strip, $order, true);
        if ($from === false) {
            throw new IllegalActionException("Strip \"{$strip}\" is not on the board.");
        }
        if ($from === $to) {
            throw new IllegalActionException("Strip \"{$strip}\" is already at position {$to}.");
        }
        array_splice($order, $from, 1);
        array_splice($order, $to, 0, [$strip]);

        return ['order' => $order];
    }

    public function legalActions(GameDefinition $definition, array $board): array
    {
        $order = self::stringList($board, 'order');
        $out = [];
        foreach ($order as $from => $strip) {
            foreach (array_keys($order) as $to) {
                if ($to !== $from) {
                    $out[] = ['type' => 'move', 'strip' => $strip, 'to' => $to];
                }
            }
        }

        return $out;
    }

    public function isComplete(GameDefinition $definition, array $board): bool
    {
        return self::stringList($board, 'order') === self::tileIds($definition);
    }

    public function progress(GameDefinition $definition, array $board): float
    {
        $order = self::stringList($board, 'order');
        $ids = self::tileIds($definition);

        return count(array_filter($order, static fn (string $id, int $i): bool => $id === $ids[$i], ARRAY_FILTER_USE_BOTH)) / max(1, count($ids));
    }

    /**
     * Moves the strip that belongs at the first wrong position into it.
     */
    public function hint(GameDefinition $definition, array $board): ?array
    {
        $order = self::stringList($board, 'order');
        foreach (self::tileIds($definition) as $i => $id) {
            if (($order[$i] ?? null) !== $id) {
                return ['type' => 'move', 'strip' => $id, 'to' => $i];
            }
        }

        return null;
    }

    public static function axis(GameDefinition $definition): string
    {
        $axis = Doc::string($definition->rules, 'axis', 'definition.rules', 16);
        if ($axis !== 'horizontal' && $axis !== 'vertical') {
            throw new InvalidDefinitionException('definition.rules.axis must be "horizontal" or "vertical".');
        }

        return $axis;
    }
}
