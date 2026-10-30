<?php

declare(strict_types=1);

namespace ImagePuzzle\Exception;

/**
 * A game definition, saved state or configuration document is malformed,
 * stale or incompatible with the current schema/rules.
 */
final class InvalidDefinitionException extends \RuntimeException implements ImagePuzzleException
{
}
