<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use GdImage;
use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Game\Doc;
use ImagePuzzle\Game\PuzzleDirectory;
use ImagePuzzle\Game\Tile;
use ImagePuzzle\ImageLoader;
use ImagePuzzle\ResourceLimits;
use ImagePuzzle\Support\ErrorCapture;

/**
 * The solved board rebuilt from a puzzle's own files: jigsaw pieces are
 * drawn at their rendered boxes, grid modes crop the board image. For a
 * correct build the result equals the source crop (up to the antialiased
 * seams between jigsaw pieces), so it doubles as a preview and a check.
 */
final class Reconstruction
{
    public function __construct(
        private readonly ResourceLimits $limits = new ResourceLimits(),
    ) {
    }

    public function render(PuzzleDirectory $puzzle): GdImage
    {
        if (!$puzzle->hasLocalImage()) {
            throw new InvalidArgumentException('The source image is remote; it cannot be reconstructed locally.');
        }
        $definition = $puzzle->definition;
        $crop = $definition->crop;
        $loader = new ImageLoader($this->limits);
        $board = imagecreatetruecolor($crop->width, $crop->height);
        imagealphablending($board, false);
        imagesavealpha($board, true);
        imagefilledrectangle($board, 0, 0, $crop->width - 1, $crop->height - 1, (int) imagecolorallocatealpha($board, 0, 0, 0, 127));
        imagealphablending($board, true);

        $pieces = 0;
        foreach ($definition->tiles as $tile) {
            if (!isset($tile->attributes['image'])) {
                continue;
            }
            $image = $loader->loadFromFile($puzzle->file(Doc::stringValue($tile->attributes['image'], "tile {$tile->id} image", 255)));
            $box = isset($tile->attributes['rendered']) ? Tile::rect($tile->attributes['rendered'], "tile {$tile->id} rendered") : $tile->rect;
            imagecopy($board, $image, $box->x - $crop->x, $box->y - $crop->y, 0, 0, imagesx($image), imagesy($image));
            $pieces++;
        }
        if ($pieces === 0) {
            $source = $loader->loadFromFile($puzzle->file($definition->image));
            imagecopy($board, $source, 0, 0, $crop->x, $crop->y, $crop->width, $crop->height);
        }

        return $board;
    }

    /** Writes the reconstruction as a new PNG file. */
    public function write(PuzzleDirectory $puzzle, string $target): void
    {
        $image = $this->render($puzzle);
        $handle = ErrorCapture::run(static fn () => fopen($target, 'xb'));
        if ($handle === false) {
            throw new ExportFailedException("Cannot create {$target} (it must not exist yet).");
        }
        try {
            if (!imagepng($image, $handle)) {
                throw new ExportFailedException("Cannot write {$target}.");
            }
        } finally {
            fclose($handle);
        }
    }
}
