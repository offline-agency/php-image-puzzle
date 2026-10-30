<?php

declare(strict_types=1);

namespace ImagePuzzle\Exception;

/**
 * An optional capability (an encoder such as WebP, ext-zip, ext-pcntl...) is
 * not available in the running PHP installation.
 */
final class CapabilityUnavailableException extends \RuntimeException implements ImagePuzzleException
{
}
