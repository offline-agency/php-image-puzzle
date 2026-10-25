<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\PieceContext;

/**
 * Decorative rounded corners for rectangular pieces.
 *
 * Pixel convention: pixel (px, py) is sampled at its centre (px + 0.5, py + 0.5).
 * For the top-left corner the arc centre is (r, r); a pixel is made fully
 * transparent only when its centre lies strictly outside that circle. The other
 * corners are mirrored. Pixels inside the arc keep their colour and alpha.
 *
 * Radius rule: the effective radius is min(radius, min(width, height) / 2), so
 * a large radius is clamped to a pill/circle shape instead of being ignored.
 *
 * Rounded corners intentionally remove corner material; they are not part of
 * the lossless reconstruction contract of jigsaw geometry.
 */
final class RoundedCornersStyle implements EdgeStyleInterface
{
    /** Upper bound of distinct radius masks cached per process (FIFO eviction). */
    public const MAX_CACHED_MASKS = 32;

    /** @var array<string, list<array{0: int, 1: int}>> effective radius -> removed top-left pixels */
    private static array $maskCache = [];

    public function __construct(
        private readonly int $radius = 4,
    ) {
        if ($this->radius < 1) {
            throw new InvalidArgumentException('Radius must be at least 1.');
        }
    }

    public function getRadius(): int
    {
        return $this->radius;
    }

    /**
     * Clear the process-wide mask cache (useful in long-running workers).
     */
    public static function clearMaskCache(): void
    {
        self::$maskCache = [];
    }

    public static function cachedMaskCount(): int
    {
        return count(self::$maskCache);
    }

    public function effectiveRadius(int $width, int $height): float
    {
        return min((float) $this->radius, min($width, $height) / 2.0);
    }

    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        $width = imagesx($piece);
        $height = imagesy($piece);
        $r = $this->effectiveRadius($width, $height);
        $transparent = imagecolorallocatealpha($piece, 0, 0, 0, 127);
        if ($transparent === false) {
            return $piece;
        }

        $previousBlending = imagealphablending($piece, false);
        imagesavealpha($piece, true);
        foreach (self::mask($r) as [$px, $py]) {
            imagesetpixel($piece, $px, $py, $transparent);
            imagesetpixel($piece, $width - 1 - $px, $py, $transparent);
            imagesetpixel($piece, $px, $height - 1 - $py, $transparent);
            imagesetpixel($piece, $width - 1 - $px, $height - 1 - $py, $transparent);
        }
        if ($previousBlending) {
            imagealphablending($piece, true);
        }

        return $piece;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function mask(float $r): array
    {
        $key = sprintf('%.1f', $r);
        if (isset(self::$maskCache[$key])) {
            return self::$maskCache[$key];
        }

        $removed = [];
        $limit = (int) ceil($r);
        $rSq = $r * $r;
        for ($py = 0; $py < $limit; $py++) {
            for ($px = 0; $px < $limit; $px++) {
                $cx = $px + 0.5;
                $cy = $py + 0.5;
                if ($cx >= $r || $cy >= $r) {
                    continue;
                }
                $dx = $cx - $r;
                $dy = $cy - $r;
                if ($dx * $dx + $dy * $dy > $rSq) {
                    $removed[] = [$px, $py];
                }
            }
        }

        if (count(self::$maskCache) >= self::MAX_CACHED_MASKS) {
            array_shift(self::$maskCache);
        }
        self::$maskCache[$key] = $removed;

        return $removed;
    }
}
