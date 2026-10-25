<?php

declare(strict_types=1);

namespace ImagePuzzle\Filament;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\Exception\UnsupportedImageTypeException;

/**
 * Splits generation failures into messages an author can act on (bad
 * configuration, image too large, unsupported file) and internal errors,
 * whose details (paths, extensions) stay in the host's error report.
 */
final class GenerationError
{
    public const INTERNAL = 'The puzzle could not be generated; the error was reported to the site administrators.';

    /** The message to show the author, or null for an internal error that must be reported. */
    public static function forAuthor(\Throwable $e): ?string
    {
        return match (true) {
            $e instanceof InvalidDefinitionException,
            $e instanceof InvalidArgumentException,
            $e instanceof ResourceLimitExceededException,
            $e instanceof UnsupportedImageTypeException,
            $e instanceof \InvalidArgumentException => $e->getMessage(),
            default => null,
        };
    }
}
