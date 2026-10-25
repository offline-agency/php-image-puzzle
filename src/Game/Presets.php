<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Versioned, mode-specific difficulty presets.
 *
 * A preset only fixes generation parameters and allowed assistance. It says
 * nothing about solution length: the sliding scramble length is the number of
 * random legal moves applied, not an optimal-distance measurement.
 * Changing any value requires bumping VERSION (definitions record it).
 */
final class Presets
{
    public const VERSION = 1;
    public const NAMES = ['easy', 'medium', 'hard'];

    private const ALL = [
        'sliding' => [
            'easy' => ['rows' => 3, 'cols' => 3, 'scrambleMoves' => 60, 'assistance' => ['preview' => true, 'numbers' => true, 'highlightCorrect' => true]],
            'medium' => ['rows' => 4, 'cols' => 4, 'scrambleMoves' => 200, 'assistance' => ['preview' => true, 'numbers' => false, 'highlightCorrect' => false]],
            'hard' => ['rows' => 5, 'cols' => 5, 'scrambleMoves' => 500, 'assistance' => ['preview' => false, 'numbers' => false, 'highlightCorrect' => false]],
        ],
        'swap' => [
            'easy' => ['rows' => 3, 'cols' => 3, 'assistance' => ['preview' => true, 'numbers' => true, 'highlightCorrect' => true]],
            'medium' => ['rows' => 4, 'cols' => 4, 'assistance' => ['preview' => true, 'numbers' => false, 'highlightCorrect' => true]],
            'hard' => ['rows' => 6, 'cols' => 6, 'assistance' => ['preview' => false, 'numbers' => false, 'highlightCorrect' => false]],
        ],
        'rotation' => [
            'easy' => ['rows' => 3, 'cols' => 3, 'orientations' => [0, 180], 'assistance' => ['preview' => true, 'numbers' => false, 'highlightCorrect' => true]],
            'medium' => ['rows' => 4, 'cols' => 4, 'orientations' => [0, 90, 180, 270], 'assistance' => ['preview' => true, 'numbers' => false, 'highlightCorrect' => false]],
            'hard' => ['rows' => 6, 'cols' => 6, 'orientations' => [0, 90, 180, 270], 'assistance' => ['preview' => false, 'numbers' => false, 'highlightCorrect' => false]],
        ],
        'strips' => [
            'easy' => ['count' => 4, 'axis' => 'horizontal', 'assistance' => ['preview' => true, 'numbers' => true, 'highlightCorrect' => true]],
            'medium' => ['count' => 8, 'axis' => 'horizontal', 'assistance' => ['preview' => true, 'numbers' => false, 'highlightCorrect' => false]],
            'hard' => ['count' => 14, 'axis' => 'vertical', 'assistance' => ['preview' => false, 'numbers' => false, 'highlightCorrect' => false]],
        ],
        'jigsaw' => [
            'easy' => ['rows' => 3, 'cols' => 4, 'edge' => 'jigsaw', 'snapRatio' => 0.25, 'assistance' => ['preview' => true, 'numbers' => false, 'highlightCorrect' => true]],
            'medium' => ['rows' => 6, 'cols' => 8, 'edge' => 'jigsaw', 'snapRatio' => 0.15, 'assistance' => ['preview' => true, 'numbers' => false, 'highlightCorrect' => false]],
            'hard' => ['rows' => 10, 'cols' => 12, 'edge' => 'jigsaw', 'snapRatio' => 0.08, 'assistance' => ['preview' => false, 'numbers' => false, 'highlightCorrect' => false]],
        ],
        'nonogram' => [
            'easy' => ['rows' => 10, 'cols' => 10, 'threshold' => 'auto', 'invert' => false, 'requireUnique' => false, 'assistance' => ['preview' => false, 'numbers' => false, 'highlightCorrect' => true]],
            'medium' => ['rows' => 15, 'cols' => 15, 'threshold' => 'auto', 'invert' => false, 'requireUnique' => false, 'assistance' => ['preview' => false, 'numbers' => false, 'highlightCorrect' => true]],
            'hard' => ['rows' => 20, 'cols' => 20, 'threshold' => 'auto', 'invert' => false, 'requireUnique' => false, 'assistance' => ['preview' => false, 'numbers' => false, 'highlightCorrect' => false]],
        ],
        'memory' => [
            'easy' => ['rows' => 3, 'cols' => 4, 'assistance' => ['preview' => true, 'numbers' => false, 'highlightCorrect' => true]],
            'medium' => ['rows' => 4, 'cols' => 4, 'assistance' => ['preview' => true, 'numbers' => false, 'highlightCorrect' => true]],
            'hard' => ['rows' => 4, 'cols' => 6, 'assistance' => ['preview' => false, 'numbers' => false, 'highlightCorrect' => false]],
        ],
        // snap: percent of the tangram unit (a quarter of the square side).
        'tangram' => [
            'easy' => ['target' => 'square', 'snap' => 50, 'assistance' => ['preview' => true, 'numbers' => false, 'highlightCorrect' => true]],
            'medium' => ['target' => 'square', 'snap' => 30, 'assistance' => ['preview' => true, 'numbers' => false, 'highlightCorrect' => false]],
            'hard' => ['target' => 'square', 'snap' => 15, 'assistance' => ['preview' => false, 'numbers' => false, 'highlightCorrect' => false]],
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function get(GameKind $kind, string $name): array
    {
        $preset = self::ALL[$kind->value][$name] ?? null;
        if ($preset === null) {
            throw new InvalidArgumentException("No \"{$name}\" preset for {$kind->value}; use one of " . implode(', ', self::NAMES) . ' or "custom".');
        }

        return $preset;
    }

    /**
     * Every preset, for documentation and the TypeScript engine.
     *
     * @return array{version: int, presets: array<string, array<string, array<string, mixed>>>}
     */
    public static function export(): array
    {
        return ['version' => self::VERSION, 'presets' => self::ALL];
    }
}
