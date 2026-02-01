<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\PieceContext;

/**
 * Applies a different edge style to each side (top, right, bottom, left).
 * Each side's style is applied to a copy of the piece; only that side's pixels are copied back.
 */
final class CompositeEdgeStyle implements EdgeStyleInterface
{
    public function __construct(
        private readonly EdgeStyleInterface $top,
        private readonly EdgeStyleInterface $right,
        private readonly EdgeStyleInterface $bottom,
        private readonly EdgeStyleInterface $left,
    ) {
    }

    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        $width = $context->getWidth();
        $height = $context->getHeight();

        imagesavealpha($piece, true);

        foreach (['top' => $this->top, 'right' => $this->right, 'bottom' => $this->bottom, 'left' => $this->left] as $side => $style) {
            $copy = $this->copyImage($piece, $width, $height);
            if ($copy === null) {
                continue;
            }
            $styled = $style->apply($copy, $context);
            $this->copyEdge($piece, $styled, $side, $width, $height);
            imagedestroy($copy);
            if ($styled !== $copy) {
                imagedestroy($styled);
            }
        }

        return $piece;
    }

    private function copyImage(GdImage $source, int $width, int $height): ?GdImage
    {
        $copy = imagecreatetruecolor($width, $height);
        if ($copy === false) {
            return null;
        }
        imagesavealpha($copy, true);
        imagealphablending($copy, false);
        imagecopy($copy, $source, 0, 0, 0, 0, $width, $height);
        imagealphablending($copy, true);
        return $copy;
    }

    private function copyEdge(GdImage $dst, GdImage $src, string $side, int $width, int $height): void
    {
        imagealphablending($dst, false);
        switch ($side) {
            case 'top':
                imagecopy($dst, $src, 0, 0, 0, 0, $width, 1);
                break;
            case 'bottom':
                imagecopy($dst, $src, 0, $height - 1, 0, $height - 1, $width, 1);
                break;
            case 'left':
                imagecopy($dst, $src, 0, 0, 0, 0, 1, $height);
                break;
            case 'right':
                imagecopy($dst, $src, $width - 1, 0, $width - 1, 0, 1, $height);
                break;
        }
        imagealphablending($dst, true);
    }
}
