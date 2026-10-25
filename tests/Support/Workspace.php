<?php

declare(strict_types=1);

namespace ImagePuzzle\Tests\Support;

use ImagePuzzle\Puzzle;

/**
 * Typed per-test state set in beforeEach hooks (instead of dynamic
 * properties on the Pest test case, which static analysis cannot see).
 */
final class Workspace
{
    public static string $dir;

    public static string $source;

    public static int $parent;

    public static Puzzle $puzzle;
}
