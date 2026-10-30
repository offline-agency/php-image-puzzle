<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

/** The size of a release: every merged pull request is at least a patch. */
enum Bump: string
{
    case Patch = 'patch';
    case Minor = 'minor';
    case Major = 'major';

    public function rank(): int
    {
        return match ($this) {
            self::Patch => 0,
            self::Minor => 1,
            self::Major => 2,
        };
    }

    public static function max(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }
}
