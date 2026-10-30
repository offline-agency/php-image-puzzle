<?php

declare(strict_types=1);

namespace ImagePuzzle\Exception;

/**
 * A request exceeds a configured {@see \ImagePuzzle\ResourceLimits} budget.
 * Thrown before expensive decoding, allocation or output whenever possible.
 */
final class ResourceLimitExceededException extends \RuntimeException implements ImagePuzzleException
{
}
