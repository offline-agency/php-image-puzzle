<?php

declare(strict_types=1);

namespace ImagePuzzle\Tests\Support;

use GdImage;
use RuntimeException;

/**
 * Coordinate-rich fixtures used as geometry oracles.
 *
 * Every pixel of a coordinate image has a unique RGB triple for images up to
 * 4096x4096, so a misplaced, duplicated or lost pixel is detectable. The left
 * third optionally carries pre-existing partial transparency.
 */
final class Fixtures
{
    public static function coordinateImage(int $width, int $height, bool $withAlpha = true): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $alpha = $withAlpha && $x < intdiv($width, 3) ? (($x + $y) % 4) * 30 : 0;
                $color = imagecolorallocatealpha($image, ...self::expectedRgb($x, $y), alpha: $alpha);
                if ($color === false) {
                    throw new RuntimeException('Color allocation failed');
                }
                imagesetpixel($image, $x, $y, $color);
            }
        }

        return $image;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function expectedRgb(int $x, int $y): array
    {
        return [$x & 0xFF, $y & 0xFF, ((($x >> 8) & 0x0F) << 4) | (($y >> 8) & 0x0F)];
    }

    /**
     * @return array{r: int, g: int, b: int, a: int}
     */
    public static function rgba(GdImage $image, int $x, int $y): array
    {
        $c = imagecolorat($image, $x, $y);

        return ['r' => ($c >> 16) & 0xFF, 'g' => ($c >> 8) & 0xFF, 'b' => $c & 0xFF, 'a' => ($c >> 24) & 0x7F];
    }

    public static function alpha(GdImage $image, int $x, int $y): int
    {
        return (imagecolorat($image, $x, $y) >> 24) & 0x7F;
    }

    public static function writePng(GdImage $image, string $path): string
    {
        if (!imagepng($image, $path)) {
            throw new RuntimeException("Cannot write fixture {$path}");
        }

        return $path;
    }

    public static function tempDir(string $prefix = 'ip-test-'): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700, true)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        return (string) realpath($dir);
    }

    public static function removeDir(string $dir): void
    {
        if (!file_exists($dir) && !is_link($dir)) {
            return;
        }
        if (is_link($dir) || is_file($dir)) {
            unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeDir($dir . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($dir);
    }
}
