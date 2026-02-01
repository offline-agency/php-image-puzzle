<?php

declare(strict_types=1);

namespace ImagePuzzle\Exception;

use InvalidArgumentException as BaseInvalidArgumentException;

final class InvalidArgumentException extends BaseInvalidArgumentException implements ImagePuzzleException
{
}
