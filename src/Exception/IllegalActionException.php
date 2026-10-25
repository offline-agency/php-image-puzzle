<?php

declare(strict_types=1);

namespace ImagePuzzle\Exception;

/**
 * A well-formed action that the rules of the current mode do not allow in the
 * current state (for example sliding a tile that is not next to the gap).
 */
final class IllegalActionException extends \RuntimeException implements ImagePuzzleException
{
}
