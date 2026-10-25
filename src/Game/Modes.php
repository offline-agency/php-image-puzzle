<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\Rules\JigsawRules;
use ImagePuzzle\Game\Rules\ModeRules;
use ImagePuzzle\Game\Rules\NonogramRules;
use ImagePuzzle\Game\Rules\RotationRules;
use ImagePuzzle\Game\Rules\SlidingRules;
use ImagePuzzle\Game\Rules\StripsRules;
use ImagePuzzle\Game\Rules\SwapRules;

/**
 * Registry of the rule modules shipped with the library.
 */
final class Modes
{
    /** @var array<string, ModeRules> */
    private static array $instances = [];

    public static function rules(GameKind $kind): ModeRules
    {
        return self::$instances[$kind->value] ??= match ($kind) {
            GameKind::Sliding => new SlidingRules(),
            GameKind::Swap => new SwapRules(),
            GameKind::Rotation => new RotationRules(),
            GameKind::Strips => new StripsRules(),
            GameKind::Jigsaw => new JigsawRules(),
            GameKind::Nonogram => new NonogramRules(),
            default => throw new InvalidDefinitionException("The {$kind->value} mode is not available in this version."),
        };
    }

    /**
     * @return list<GameKind>
     */
    public static function available(): array
    {
        return [GameKind::Sliding, GameKind::Swap, GameKind::Rotation, GameKind::Strips, GameKind::Jigsaw, GameKind::Nonogram];
    }
}
