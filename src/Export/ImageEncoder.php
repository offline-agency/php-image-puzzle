<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use GdImage;
use ImagePuzzle\Exception\CapabilityUnavailableException;
use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\Support\ErrorCapture;

/**
 * Format-specific encoding into newly created files, plus post-write checks.
 * Encoder return values alone do not prove that a valid image was written, so
 * every file is re-read with getimagesize() and compared with expectations.
 */
final class ImageEncoder
{
    /**
     * @return array<string, bool>
     */
    public static function capabilities(): array
    {
        $info = gd_info();

        return [
            ExportOptions::FORMAT_PNG => (bool) ($info['PNG Support'] ?? false),
            ExportOptions::FORMAT_JPEG => (bool) ($info['JPEG Support'] ?? false),
            ExportOptions::FORMAT_WEBP => (bool) ($info['WebP Support'] ?? false) && function_exists('imagewebp'),
        ];
    }

    public static function assertSupported(string $format): void
    {
        if (!(self::capabilities()[$format] ?? false)) {
            throw new CapabilityUnavailableException("This GD build cannot encode {$format} images.");
        }
    }

    /**
     * Encode into $path, which must not exist yet (exclusive create).
     */
    public static function write(GdImage $image, string $path, ExportOptions $options): void
    {
        $format = $options->getFormat();
        self::assertSupported($format);
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new ExportFailedException("Failed to create file: {$path}");
        }
        try {
            $ok = match ($format) {
                ExportOptions::FORMAT_PNG => self::png($image, $handle, $options->getQuality()),
                ExportOptions::FORMAT_JPEG => imagejpeg(self::flatten($image, $options->getJpegBackground()), $handle, $options->getQuality()),
                ExportOptions::FORMAT_WEBP => self::webp($image, $handle, $options->getQuality()),
                default => false,
            };
        } finally {
            fclose($handle);
        }
        if (!$ok) {
            @unlink($path);
            throw new ExportFailedException("Failed to write image: {$path}");
        }
        self::verify($path, $format, imagesx($image), imagesy($image));
    }

    public static function verify(string $path, string $format, int $width, int $height): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || !is_file($path) || (int) filesize($path) === 0) {
            throw new ExportFailedException("Image file is missing or empty: {$path}");
        }
        $size = ErrorCapture::run(static fn (): array|false => getimagesize($path));
        $type = [ExportOptions::FORMAT_PNG => IMAGETYPE_PNG, ExportOptions::FORMAT_JPEG => IMAGETYPE_JPEG, ExportOptions::FORMAT_WEBP => IMAGETYPE_WEBP][$format] ?? null;
        if ($size === false || $size[2] !== $type || $size[0] !== $width || $size[1] !== $height) {
            $found = $size === false ? 'undecodable' : "{$size[0]}x{$size[1]} type {$size[2]}";
            throw new ExportFailedException("Image file {$path} does not match the expected {$format} {$width}x{$height} ({$found}).");
        }
    }

    /**
     * @param resource $handle
     */
    private static function png(GdImage $image, $handle, int $level): bool
    {
        imagesavealpha($image, true);

        return imagepng($image, $handle, $level);
    }

    /**
     * @param resource $handle
     */
    private static function webp(GdImage $image, $handle, int $quality): bool
    {
        imagesavealpha($image, true);

        return imagewebp($image, $handle, $quality);
    }

    /**
     * @param array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>} $rgb
     */
    private static function flatten(GdImage $image, array $rgb): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $flat = imagecreatetruecolor($w, $h);
        $background = imagecolorallocate($flat, $rgb[0], $rgb[1], $rgb[2]);
        if ($background === false) {
            throw new ExportFailedException('Failed to allocate JPEG background colour.');
        }
        imagefilledrectangle($flat, 0, 0, $w - 1, $h - 1, $background);
        imagealphablending($flat, true);
        imagecopy($flat, $image, 0, 0, 0, 0, $w, $h);

        return $flat;
    }
}
