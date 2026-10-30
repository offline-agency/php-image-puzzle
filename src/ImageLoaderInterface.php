<?php

declare(strict_types=1);

namespace ImagePuzzle;

use GdImage;

/**
 * Loads a source image. Implementations must return a truecolor image owned
 * by the caller and throw an ImagePuzzleException for invalid input.
 */
interface ImageLoaderInterface
{
    public function loadFromFile(string $path): GdImage;

    public function inspect(string $path): ImageInfo;
}
