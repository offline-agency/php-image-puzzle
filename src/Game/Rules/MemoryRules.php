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
 * Memory (pairs): find the two cards of every pair.
 *
 * Definition: board.rows x board.cols card positions (an even count, 4-64).
 * The image (board.crop) is divided into rules.regions.rows x
 * rules.regions.cols equal regions, one per pair. Tiles are the cards, two
 * per pair and listed pair by pair: "p{k}-a" then "p{k}-b", with home = tile
 * index, rect = region k and attributes pair ("p{k}"), face ("image" or
 * "text") and, for text faces, text. The first card of a pair always shows
 * the image region; the second shows it too, or a text label (image/text
 * association). Card IDs never depend on what a card shows.
 *
 * Board: {"cards": [cardId, ...] by position (row-major), "matched":
 * [cardId, ...] in the order they were matched, "revealed": [cardId, ...]
 * face-up cards that are not matched, at most two}.
 *
 * Action {"type": "flip", "card": id}. Rules, all deterministic:
 * - a matched card, or the single face-up card, cannot be flipped;
 * - when two unmatched cards are face up (a mismatch), the next flip first
 *   turns them face down, then reveals its card: flipping one of those two
 *   cards is allowed and reveals it alone;
 * - when the second face-up card has the same pair as the first, both move
 *   to matched at once.
 * How long a mismatch stays visible is up to the player (a visual delay); it
 * never changes the state. Undo, redo and hints are disabled; reset and
 * shuffle deal a new layout.
 */
final class MemoryRules implements ModeRules
{
    public const MIN_SIDE = 2;
    public const MAX_SIDE = 8;
    public const MAX_TEXT_BYTES = 120;

    public function kind(): GameKind
    {
        return GameKind::Memory;
    }

    public function actionTypes(): array
    {
        return ['flip'];
    }

    public function capabilities(): array
    {
        return ['undo' => false, 'redo' => false, 'reset' => true, 'shuffle' => true, 'hint' => false];
    }

    public function validateDefinition(GameDefinition $definition): void
    {
        $rows = $definition->rows;
        $cols = $definition->cols;
        if ($rows < self::MIN_SIDE || $cols < self::MIN_SIDE || $rows > self::MAX_SIDE || $cols > self::MAX_SIDE || ($rows * $cols) % 2 !== 0) {
            throw new InvalidDefinitionException('A memory board must be between ' . self::MIN_SIDE . 'x' . self::MIN_SIDE . ' and ' . self::MAX_SIDE . 'x' . self::MAX_SIDE . " with an even number of cards, got {$rows}x{$cols}.");
        }
        $pairs = intdiv($rows * $cols, 2);
        Doc::keys($definition->rules, ['regions'], 'definition.rules');
        $regions = Doc::object($definition->rules['regions'] ?? null, 'definition.rules.regions');
        Doc::keys($regions, ['rows', 'cols'], 'definition.rules.regions');
        $regionRows = Doc::int($regions, 'rows', 'definition.rules.regions', 1, $pairs);
        $regionCols = Doc::int($regions, 'cols', 'definition.rules.regions', 1, $pairs);
        if ($regionRows * $regionCols !== $pairs) {
            throw new InvalidDefinitionException("definition.rules.regions must hold exactly {$pairs} regions.");
        }
        $crop = $definition->crop;
        if ($crop->width % $regionCols !== 0 || $crop->height % $regionRows !== 0) {
            throw new InvalidDefinitionException('board.crop must divide into equal regions.');
        }
        $tiles = $definition->tiles;
        if (count($tiles) !== 2 * $pairs) {
            throw new InvalidDefinitionException('A ' . $rows . 'x' . $cols . ' memory board needs exactly ' . (2 * $pairs) . ' cards, got ' . count($tiles) . '.');
        }
        $w = intdiv($crop->width, $regionCols);
        $h = intdiv($crop->height, $regionRows);
        foreach ($tiles as $index => $tile) {
            $pair = intdiv($index, 2);
            $side = $index % 2 === 0 ? 'a' : 'b';
            if ($tile->id !== "p{$pair}-{$side}" || $tile->home !== $index) {
                throw new InvalidDefinitionException("Card {$index} must be \"p{$pair}-{$side}\" with home {$index} (cards are listed pair by pair).");
            }
            $x = $crop->x + ($pair % $regionCols) * $w;
            $y = $crop->y + intdiv($pair, $regionCols) * $h;
            if ($tile->rect->x !== $x || $tile->rect->y !== $y || $tile->rect->width !== $w || $tile->rect->height !== $h) {
                throw new InvalidDefinitionException("Card {$tile->id} does not show region {$pair} of board.crop.");
            }
            $path = "definition.tiles[{$index}]";
            $attributes = $tile->attributes;
            if (($attributes['pair'] ?? null) !== "p{$pair}") {
                throw new InvalidDefinitionException("{$path}.pair must be \"p{$pair}\".");
            }
            $face = $attributes['face'] ?? null;
            if ($face === 'image') {
                Doc::keys($attributes, ['pair', 'face'], $path);
            } elseif ($face === 'text' && $side === 'b') {
                Doc::keys($attributes, ['pair', 'face', 'text'], $path);
                self::text($attributes['text'] ?? null, "{$path}.text");
            } else {
                throw new InvalidDefinitionException("{$path}.face must be \"image\"" . ($side === 'b' ? ' or "text"' : ' (the first card of a pair shows the image)') . '.');
            }
        }
    }

    /**
     * A card label: 1-120 bytes of valid UTF-8 without control characters or
     * surrounding whitespace.
     */
    public static function text(mixed $value, string $path): string
    {
        $text = Doc::stringValue($value, $path, self::MAX_TEXT_BYTES);
        if ($text === '' || trim($text) !== $text || preg_match('/^[^\p{Cc}]+$/u', $text) !== 1) {
            throw new InvalidDefinitionException("{$path} must be 1-" . self::MAX_TEXT_BYTES . ' bytes of text without control characters or surrounding spaces.');
        }

        return $text;
    }

    public function solvedBoard(GameDefinition $definition): array
    {
        $ids = self::ids($definition);

        return ['cards' => $ids, 'matched' => $ids, 'revealed' => []];
    }

    public function scramble(GameDefinition $definition, int $seed): array
    {
        return ['cards' => (new Prng($seed, 'image-puzzle/scramble/memory'))->shuffle(self::ids($definition)), 'matched' => [], 'revealed' => []];
    }

    public function validateBoard(GameDefinition $definition, mixed $board): array
    {
        $board = Doc::object($board, 'board');
        Doc::keys($board, ['cards', 'matched', 'revealed'], 'board');
        $ids = self::ids($definition);
        $cards = GridRules::permutation($board['cards'] ?? null, $ids, 'board.cards');
        $matched = self::subset($board['matched'] ?? null, $ids, 'board.matched', count($ids));
        foreach ($matched as $id) {
            if (!in_array(self::partner($id), $matched, true)) {
                throw new InvalidDefinitionException("board.matched has card {$id} without its partner.");
            }
        }
        $revealed = self::subset($board['revealed'] ?? null, $ids, 'board.revealed', 2);
        foreach ($revealed as $id) {
            if (in_array($id, $matched, true)) {
                throw new InvalidDefinitionException("board.revealed has the matched card {$id}.");
            }
        }
        if (count($revealed) === 2 && self::pair($revealed[0]) === self::pair($revealed[1])) {
            throw new InvalidDefinitionException('board.revealed holds a pair that should be matched.');
        }

        return ['cards' => $cards, 'matched' => $matched, 'revealed' => $revealed];
    }

    public function apply(GameDefinition $definition, array $board, array $action): array
    {
        $type = Doc::string($action, 'type', 'action', 32);
        if ($type !== 'flip') {
            throw new IllegalActionException("The memory mode does not support \"{$type}\" actions.");
        }
        Doc::keys($action, ['type', 'card'], 'action');
        $card = Doc::id($action['card'] ?? null, 'action.card');
        $cards = self::list($board, 'cards');
        if (!in_array($card, $cards, true)) {
            throw new IllegalActionException("Card \"{$card}\" is not on the board.");
        }
        $matched = self::list($board, 'matched');
        if (in_array($card, $matched, true)) {
            throw new IllegalActionException("Card \"{$card}\" is already matched.");
        }
        $revealed = self::list($board, 'revealed');
        if (count($revealed) === 2) {
            // A mismatch: the next flip turns both face down first.
            $revealed = [];
        }
        if (in_array($card, $revealed, true)) {
            throw new IllegalActionException("Card \"{$card}\" is already face up.");
        }
        $revealed[] = $card;
        if (count($revealed) === 2 && self::pair($revealed[0]) === self::pair($revealed[1])) {
            $matched = [...$matched, ...$revealed];
            $revealed = [];
        }

        return ['cards' => $cards, 'matched' => $matched, 'revealed' => $revealed];
    }

    public function legalActions(GameDefinition $definition, array $board): array
    {
        $matched = self::list($board, 'matched');
        $revealed = self::list($board, 'revealed');
        $out = [];
        foreach (self::list($board, 'cards') as $card) {
            if (!in_array($card, $matched, true) && !(count($revealed) === 1 && $revealed[0] === $card)) {
                $out[] = ['type' => 'flip', 'card' => $card];
            }
        }

        return $out;
    }

    public function isComplete(GameDefinition $definition, array $board): bool
    {
        return count(self::list($board, 'matched')) === count($definition->tiles);
    }

    public function progress(GameDefinition $definition, array $board): float
    {
        return count(self::list($board, 'matched')) / max(1, count($definition->tiles));
    }

    /** No hints: they would reveal hidden cards. */
    public function hint(GameDefinition $definition, array $board): ?array
    {
        return null;
    }

    /** The pair ID ("p3") of a card ID ("p3-a"). */
    public static function pair(string $card): string
    {
        return substr($card, 0, -2);
    }

    private static function partner(string $card): string
    {
        return self::pair($card) . (str_ends_with($card, '-a') ? '-b' : '-a');
    }

    /**
     * @return list<string>
     */
    private static function ids(GameDefinition $definition): array
    {
        return array_map(static fn ($tile): string => $tile->id, $definition->tiles);
    }

    /**
     * Distinct known card IDs, at most $max.
     *
     * @param list<string> $ids
     * @return list<string>
     */
    private static function subset(mixed $value, array $ids, string $path, int $max): array
    {
        $out = [];
        foreach (Doc::list($value, $path, $max) as $i => $item) {
            $id = Doc::id($item, "{$path}[{$i}]");
            if (!in_array($id, $ids, true)) {
                throw new InvalidDefinitionException("{$path}[{$i}] is an unknown card \"{$id}\".");
            }
            if (in_array($id, $out, true)) {
                throw new InvalidDefinitionException("{$path}[{$i}] repeats card \"{$id}\".");
            }
            $out[] = $id;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $board
     * @return list<string>
     */
    private static function list(array $board, string $key): array
    {
        $out = [];
        foreach (Doc::list($board[$key] ?? null, "board.{$key}") as $i => $item) {
            $out[] = Doc::stringValue($item, "board.{$key}[{$i}]", 32);
        }

        return $out;
    }
}
