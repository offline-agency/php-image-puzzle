<?php

declare(strict_types=1);

namespace ImagePuzzle\Exception;

/** A storage backend failed, or an object it should hold is missing or different. */
final class StorageException extends \RuntimeException implements ImagePuzzleException
{
}
