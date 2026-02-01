<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\PieceContext;

/**
 * Rounds the four corners of each piece by making corner pixels transparent.
 * Corner radius is configurable; no tab/socket geometry.
 * Uses a cached quarter-circle mask per radius for performance.
 */
final class RoundedCornersStyle implements EdgeStyleInterface
{
    /** @var array<int, GdImage> radius -> mask image (r×r, quarter-circle transparent) */
    private static array $maskCache = [];

    public function __construct(
        private readonly int $radius = 4,
    ) {
        if ($this->radius < 1) {
            throw new InvalidArgumentException('Radius must be at least 1.');
        }
    }

    /**
     * Clear the static mask cache. Use in long-running processes if many different radii were used.
     */
    public static function clearMaskCache(): void
    {
        self::$maskCache = [];
    }

    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        $width = $context->getWidth();
        $height = $context->getHeight();
        if ($this->radius >= min($width, $height) / 2) {
            return $piece;
        }

        $r = min($this->radius, (int) floor(min($width, $height) / 2));
        $mask = $this->getOrCreateMask($r);
        $transparent = imagecolorallocatealpha($piece, 0, 0, 0, 127);
        if ($transparent === false) {
            return $piece;
        }

        imagesavealpha($piece, true);
        imagealphablending($piece, false);

        for ($py = 0; $py < $r; $py++) {
            for ($px = 0; $px < $r; $px++) {
                if (!$this->isMaskPixelTransparent($mask, $px, $py)) {
                    continue;
                }
                imagesetpixel($piece, $px, $py, $transparent);
                imagesetpixel($piece, $width - 1 - $px, $py, $transparent);
                imagesetpixel($piece, $px, $height - 1 - $py, $transparent);
                imagesetpixel($piece, $width - 1 - $px, $height - 1 - $py, $transparent);
            }
        }

        imagealphablending($piece, true);
        return $piece;
    }

    private function getOrCreateMask(int $r): GdImage
    {
        if (isset(self::$maskCache[$r])) {
            return self::$maskCache[$r];
        }
        $mask = imagecreatetruecolor($r, $r);
        if ($mask === false) {
            throw new \RuntimeException('Failed to create corner mask image');
        }
        imagesavealpha($mask, true);
        imagealphablending($mask, false);
        $transparent = imagecolorallocatealpha($mask, 0, 0, 0, 127);
        if ($transparent !== false) {
            imagefill($mask, 0, 0, $transparent);
        }
        $rSq = $r * $r;
        for ($py = 0; $py < $r; $py++) {
            for ($px = 0; $px < $r; $px++) {
                $dx = $px - $r;
                $dy = $py - $r;
                if ($dx * $dx + $dy * $dy > $rSq) {
                    if ($transparent !== false) {
                        imagesetpixel($mask, $px, $py, $transparent);
                    }
                }
            }
        }
        imagealphablending($mask, true);
        self::$maskCache[$r] = $mask;
        return $mask;
    }

    private function isMaskPixelTransparent(GdImage $mask, int $x, int $y): bool
    {
        $color = imagecolorat($mask, $x, $y);
        $alpha = ($color >> 24) & 0x7F;
        return $alpha === 127;
    }
}
