<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry;

use GdImage;
use RuntimeException;

/**
 * Renders a piece image from the source and a shape.
 *
 * The output has the size of the shape's rendered bounds. Pixels fully covered
 * by the outline are copied unchanged (colour and alpha); partially covered
 * pixels keep their colour and have their opacity multiplied by the coverage:
 * opacity' = round(opacity * coverage / maxCoverage), with opacity = 127 - alpha.
 *
 * Compositing contract: summing the opacities of all pieces on a pixel
 * reproduces the source opacity within (n / 2) alpha units, n being the number
 * of pieces partially covering the pixel ("plus-lighter"/additive compositing).
 * With samples = 1 every pixel is owned by exactly one piece and the source is
 * reconstructed bit-exactly.
 */
final readonly class PieceRenderer
{
    public function __construct(
        private Rasterizer $rasterizer = new Rasterizer(),
    ) {
    }

    public function rasterizer(): Rasterizer
    {
        return $this->rasterizer;
    }

    public function render(GdImage $source, PieceShape $shape): GdImage
    {
        $bounds = $shape->rendered;
        $piece = imagecreatetruecolor($bounds->width, $bounds->height);
        imagealphablending($piece, false);
        imagesavealpha($piece, true);
        $transparent = imagecolorallocatealpha($piece, 0, 0, 0, 127);
        if ($transparent === false) {
            throw new RuntimeException('Failed to allocate transparent colour.');
        }
        imagefilledrectangle($piece, 0, 0, $bounds->width - 1, $bounds->height - 1, $transparent);

        $sourceIsTrueColor = imageistruecolor($source);
        $max = $this->rasterizer->maxCoverage();
        $this->rasterizer->scan($shape->polygon, $bounds, static function (int $y, array $coverage) use ($piece, $source, $bounds, $max, $sourceIsTrueColor): void {
            $ly = $y - $bounds->y;
            $runStart = -1;
            $w = count($coverage);
            for ($lx = 0; $lx <= $w; $lx++) {
                $c = $lx < $w ? $coverage[$lx] : -1;
                if ($c === $max) {
                    if ($runStart < 0) {
                        $runStart = $lx;
                    }
                    continue;
                }
                if ($runStart >= 0) {
                    imagecopy($piece, $source, $runStart, $ly, $bounds->x + $runStart, $y, $lx - $runStart, 1);
                    $runStart = -1;
                }
                if ($c > 0) {
                    $rgba = imagecolorat($source, $bounds->x + $lx, $y);
                    if (!$sourceIsTrueColor) {
                        $i = imagecolorsforindex($source, $rgba);
                        $rgba = ($i['alpha'] << 24) | ($i['red'] << 16) | ($i['green'] << 8) | $i['blue'];
                    }
                    $opacity = 127 - (($rgba >> 24) & 0x7F);
                    $alpha = 127 - (int) round($opacity * $c / $max);
                    imagesetpixel($piece, $lx, $ly, ($alpha << 24) | ($rgba & 0xFFFFFF));
                }
            }
        });

        return $piece;
    }
}
