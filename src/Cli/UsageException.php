<?php

declare(strict_types=1);

namespace ImagePuzzle\Cli;

use ImagePuzzle\Exception\ImagePuzzleException;

/** A command line that does not match the command's synopsis (exit code 2). */
final class UsageException extends \InvalidArgumentException implements ImagePuzzleException
{
}
