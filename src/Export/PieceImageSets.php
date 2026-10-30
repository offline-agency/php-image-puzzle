<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use GdImage;
use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\Doc;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\PieceAtlas;
use ImagePuzzle\Game\PieceImages;
use ImagePuzzle\Game\PieceResolution;
use ImagePuzzle\Game\Tile;
use ImagePuzzle\Geometry\Bounds;
use ImagePuzzle\ResourceLimits;
use ImagePuzzle\Support\ErrorCapture;

/**
 * Writes the alternative images of a built jigsaw definition: smaller piece
 * sets and an optional atlas. Every alternative is resampled from the
 * full-resolution piece image, so a piece keeps its ID, its alpha outline
 * and its placement: only the pixel density changes.
 *
 * Atlas frames are separated by transparent padding, and the outermost
 * pixels of each frame are repeated into half of it (extrusion), so bilinear
 * sampling at a frame edge never picks up a neighbouring piece.
 */
final class PieceImageSets
{
    public function __construct(
        private readonly ResourceLimits $limits = new ResourceLimits(),
    ) {
    }

    public function write(string $directory, GameDefinition $definition, AssetOptions $assets, ExportOptions $encoding): PieceImages
    {
        if ($definition->kind !== GameKind::Jigsaw) {
            throw new InvalidArgumentException('Piece resolutions and atlases are only available for jigsaw puzzles.');
        }
        $resolutions = [];
        foreach ($assets->resolutions as $name => $width) {
            if ($width < $definition->sourceWidth) {
                $resolutions[] = new PieceResolution($name, $width, 'pieces-' . $name);
            }
        }
        $atlasSet = null;
        if ($assets->atlas !== null && $assets->atlas !== AssetOptions::ATLAS_FULL) {
            foreach ($resolutions as $resolution) {
                if ($resolution->name === $assets->atlas) {
                    $atlasSet = $resolution;
                }
            }
            // A resolution at least as wide as the source was skipped: pack the full pieces.
        }

        $tiles = [];
        foreach ($definition->tiles as $tile) {
            $tiles[] = [$tile, Tile::rect($tile->attribute('rendered'), "tile {$tile->id} rendered"), Doc::stringValue($tile->attribute('image'), "tile {$tile->id} image", 255)];
        }
        $atlas = null;
        $layout = null;
        if ($assets->atlas !== null) {
            $sizes = [];
            foreach ($tiles as [$tile, $rendered]) {
                $sizes[$tile->id] = $atlasSet === null
                    ? [$rendered->width, $rendered->height]
                    : [$atlasSet->scaled($rendered->width, $definition->sourceWidth), $atlasSet->scaled($rendered->height, $definition->sourceWidth)];
            }
            $layout = AtlasPacker::pack($sizes, $assets->atlasPadding, $assets->atlasMaxSize);
            $this->limits->assertDimensions($layout['width'], $layout['height'], 'Atlas');
            $atlas = self::canvas($layout['width'], $layout['height']);
        }
        foreach ($resolutions as $resolution) {
            self::mkdir($directory . '/' . $resolution->directory);
        }

        foreach ($tiles as [$tile, $rendered, $image]) {
            $full = self::load($directory . '/' . $image);
            if (imagesx($full) !== $rendered->width || imagesy($full) !== $rendered->height) {
                throw new ExportFailedException("Piece image {$image} is not {$rendered->width}x{$rendered->height} pixels.");
            }
            foreach ($resolutions as $resolution) {
                $scaled = self::resample($full, $resolution->scaled($rendered->width, $definition->sourceWidth), $resolution->scaled($rendered->height, $definition->sourceWidth));
                ImageEncoder::write($scaled, $directory . '/' . $resolution->directory . '/' . basename($image), $encoding);
                if ($atlas !== null && $layout !== null && $atlasSet === $resolution) {
                    self::blit($atlas, $scaled, $layout['frames'][$tile->id], intdiv($assets->atlasPadding, 2));
                }
            }
            if ($atlas !== null && $layout !== null && $atlasSet === null) {
                self::blit($atlas, $full, $layout['frames'][$tile->id], intdiv($assets->atlasPadding, 2));
            }
        }

        $pieceAtlas = null;
        if ($atlas !== null && $layout !== null) {
            $name = 'atlas.' . pathinfo($encoding->resolvePieceFilename(0, 0), PATHINFO_EXTENSION);
            ImageEncoder::write($atlas, $directory . '/' . $name, $encoding);
            $pieceAtlas = new PieceAtlas($name, $layout['width'], $layout['height'], $layout['frames']);
        }

        return new PieceImages($resolutions, $pieceAtlas);
    }

    private static function load(string $path): GdImage
    {
        $bytes = ErrorCapture::run(static fn (): string|false => file_get_contents($path));
        $image = $bytes === false ? false : ErrorCapture::run(static fn (): GdImage|false => imagecreatefromstring($bytes));
        if (!$image instanceof GdImage) {
            throw new ExportFailedException("Cannot read the piece image {$path}.");
        }
        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private static function canvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        if ($clear === false) {
            throw new ExportFailedException('Cannot allocate a transparent colour.');
        }
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $clear);

        return $canvas;
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private static function resample(GdImage $source, int $width, int $height): GdImage
    {
        $target = self::canvas($width, $height);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $target;
    }

    /** Copies $image into $frame and repeats its border pixels $extrude pixels outwards. */
    private static function blit(GdImage $atlas, GdImage $image, Bounds $frame, int $extrude): void
    {
        ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h] = $frame->toArray();
        imagecopy($atlas, $image, $x, $y, 0, 0, $w, $h);
        for ($i = 1; $i <= $extrude; $i++) {
            imagecopy($atlas, $atlas, $x - $i, $y, $x, $y, 1, $h);
            imagecopy($atlas, $atlas, $x + $w - 1 + $i, $y, $x + $w - 1, $y, 1, $h);
        }
        for ($i = 1; $i <= $extrude; $i++) {
            imagecopy($atlas, $atlas, $x - $extrude, $y - $i, $x - $extrude, $y, $w + 2 * $extrude, 1);
            imagecopy($atlas, $atlas, $x - $extrude, $y + $h - 1 + $i, $x - $extrude, $y + $h - 1, $w + 2 * $extrude, 1);
        }
    }

    private static function mkdir(string $path): void
    {
        if (!is_dir($path) && !ErrorCapture::run(static fn (): bool => mkdir($path, 0755, true))) {
            throw new ExportFailedException("Cannot create {$path}.");
        }
    }
}
