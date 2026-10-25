<?php

declare(strict_types=1);

namespace ImagePuzzle;

use GdImage;
use ImagePuzzle\Exception\CapabilityUnavailableException;
use ImagePuzzle\Exception\GdNotAvailableException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\UnsupportedImageTypeException;
use ImagePuzzle\Support\ErrorCapture;

/**
 * Loads PNG, JPEG, GIF and (when GD supports it) WebP sources.
 *
 * - Type detection uses the file signature plus getimagesize(); ext-exif is
 *   not required.
 * - Byte, dimension and pixel budgets are checked from the header before
 *   decoding, and again on the decoded image.
 * - Any decoder warning (truncated or corrupt data) rejects the input.
 * - JPEG EXIF orientation is ignored by default (pixels are used as stored);
 *   pass $applyExifOrientation = true to rotate/flip accordingly. The
 *   orientation tag is parsed in pure PHP.
 * - Animated GIFs: only the first frame is used; inspect() reports the frame
 *   count. Animation is never preserved.
 * - The returned image is always truecolor with alpha saving enabled and is
 *   owned by the caller.
 */
final class ImageLoader implements ImageLoaderInterface
{
    private const SIGNATURES = [
        'png' => "\x89PNG\r\n\x1a\n",
        'jpeg' => "\xFF\xD8\xFF",
        'gif87' => 'GIF87a',
        'gif89' => 'GIF89a',
    ];

    private readonly ResourceLimits $limits;

    public function __construct(
        ?ResourceLimits $limits = null,
        private readonly bool $applyExifOrientation = false,
    ) {
        if (!extension_loaded('gd')) {
            throw new GdNotAvailableException('The GD extension is required but not loaded.');
        }
        $this->limits = $limits ?? new ResourceLimits();
    }

    /**
     * Decoders available in this GD build, by type.
     *
     * @return array<string, bool>
     */
    public static function decoders(): array
    {
        $info = gd_info();

        return [
            'png' => (bool) ($info['PNG Support'] ?? false),
            'jpeg' => (bool) ($info['JPEG Support'] ?? false),
            'gif' => (bool) ($info['GIF Read Support'] ?? false),
            'webp' => (bool) ($info['WebP Support'] ?? false),
        ];
    }

    public function inspect(string $path): ImageInfo
    {
        if ($path === '') {
            throw new InvalidArgumentException('Image path cannot be empty.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Image file is not readable: {$path}");
        }
        $bytes = filesize($path);
        if ($bytes === false || $bytes === 0) {
            throw new UnsupportedImageTypeException("Image file is empty: {$path}");
        }
        $this->limits->assertInputBytes($bytes, 'Image file');

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException("Image file is not readable: {$path}");
        }
        $head = (string) fread($handle, 16);
        fclose($handle);

        $type = self::detectSignature($head);
        if ($type === null) {
            throw new UnsupportedImageTypeException("Unsupported image type: {$path}. Only PNG, JPEG, GIF and WebP are supported.");
        }

        $size = ErrorCapture::run(static fn (): array|false => getimagesize($path));
        if (!is_array($size) || $size[0] < 1 || $size[1] < 1) {
            throw new UnsupportedImageTypeException("Could not read image header: {$path}");
        }
        $expected = ['png' => IMAGETYPE_PNG, 'jpeg' => IMAGETYPE_JPEG, 'gif' => IMAGETYPE_GIF, 'webp' => IMAGETYPE_WEBP][$type];
        if ($size[2] !== $expected) {
            throw new UnsupportedImageTypeException("Image signature and header disagree: {$path}");
        }

        $frames = 1;
        $orientation = null;
        if ($type === 'gif' || $type === 'jpeg') {
            $data = (string) file_get_contents($path);
            if ($type === 'gif') {
                $frames = self::countGifFrames($data);
            } else {
                $orientation = self::jpegOrientation($data);
            }
        }

        $info = new ImageInfo($type, $size[0], $size[1], $bytes, $frames, $orientation);
        $this->limits->assertDimensions($info->width, $info->height, 'Image');

        return $info;
    }

    public function loadFromFile(string $path): GdImage
    {
        $info = $this->inspect($path);
        if (!(self::decoders()[$info->type] ?? false)) {
            throw new CapabilityUnavailableException("This GD build cannot decode {$info->type} images.");
        }

        $decoder = match ($info->type) {
            'png' => static fn (): GdImage|false => imagecreatefrompng($path),
            'jpeg' => static fn (): GdImage|false => imagecreatefromjpeg($path),
            'gif' => static fn (): GdImage|false => imagecreatefromgif($path),
            'webp' => static fn (): GdImage|false => imagecreatefromwebp($path),
            default => throw new UnsupportedImageTypeException("Unsupported image type: {$info->type}"),
        };

        $warnings = [];
        $image = ErrorCapture::run($decoder, $warnings);
        if (!$image instanceof GdImage || $warnings !== []) {
            throw new UnsupportedImageTypeException(
                "Failed to load image: {$path}" . ($warnings !== [] ? ' (' . $warnings[0] . ')' : '')
            );
        }

        if (!imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }
        imagesavealpha($image, true);

        if ($this->applyExifOrientation && $info->orientation !== null && $info->orientation !== 1) {
            $image = self::orient($image, $info->orientation);
        }

        $this->limits->assertDimensions(imagesx($image), imagesy($image), 'Decoded image');

        return $image;
    }

    private static function detectSignature(string $head): ?string
    {
        foreach (self::SIGNATURES as $type => $signature) {
            if (str_starts_with($head, $signature)) {
                return str_starts_with($type, 'gif') ? 'gif' : $type;
            }
        }
        if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return null;
    }

    /**
     * Count image descriptors by walking the GIF block structure.
     */
    private static function countGifFrames(string $data): int
    {
        $len = strlen($data);
        if ($len < 13) {
            return 1;
        }
        $pos = 13;
        $flags = ord($data[10]);
        if (($flags & 0x80) !== 0) {
            $pos += 3 * (2 ** (($flags & 0x07) + 1));
        }
        $frames = 0;
        while ($pos < $len) {
            $byte = ord($data[$pos]);
            if ($byte === 0x3B) {
                break;
            }
            if ($byte === 0x21) {
                $pos += 2;
                $pos = self::skipSubBlocks($data, $pos, $len);
                continue;
            }
            if ($byte === 0x2C) {
                $frames++;
                if ($pos + 10 > $len) {
                    break;
                }
                $localFlags = ord($data[$pos + 9]);
                $pos += 10;
                if (($localFlags & 0x80) !== 0) {
                    $pos += 3 * (2 ** (($localFlags & 0x07) + 1));
                }
                $pos++; // LZW minimum code size
                $pos = self::skipSubBlocks($data, $pos, $len);
                continue;
            }
            break;
        }

        return max(1, $frames);
    }

    private static function skipSubBlocks(string $data, int $pos, int $len): int
    {
        while ($pos < $len) {
            $size = ord($data[$pos]);
            $pos++;
            if ($size === 0) {
                break;
            }
            $pos += $size;
        }

        return $pos;
    }

    /**
     * Read the EXIF Orientation tag (0x0112) from a JPEG APP1 segment.
     */
    private static function jpegOrientation(string $data): ?int
    {
        $len = strlen($data);
        $pos = 2;
        while ($pos + 4 <= $len && ord($data[$pos]) === 0xFF) {
            $marker = ord($data[$pos + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                return null;
            }
            $segmentLength = (ord($data[$pos + 2]) << 8) | ord($data[$pos + 3]);
            if ($marker === 0xE1 && substr($data, $pos + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($data, $pos + 10, $segmentLength - 8));
            }
            $pos += 2 + $segmentLength;
        }

        return null;
    }

    private static function tiffOrientation(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }
        $little = substr($tiff, 0, 2) === 'II';
        $u16 = static fn (int $o): int => strlen($tiff) >= $o + 2 ? (int) unpack($little ? 'v' : 'n', $tiff, $o)[1] : 0;
        $u32 = static fn (int $o): int => strlen($tiff) >= $o + 4 ? (int) unpack($little ? 'V' : 'N', $tiff, $o)[1] : 0;
        $ifd = $u32(4);
        $count = $u16($ifd);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }

    private static function orient(GdImage $image, int $orientation): GdImage
    {
        // imagerotate() angles are counter-clockwise: 270 is a 90 degree clockwise turn.
        if (in_array($orientation, [2, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif (in_array($orientation, [4, 5], true)) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 6, 7 => 270,
            8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            $rotated = imagerotate($image, $angle, $transparent === false ? 0 : $transparent);
            if ($rotated === false) {
                throw new UnsupportedImageTypeException('Failed to apply EXIF orientation.');
            }
            imagesavealpha($rotated, true);

            return $rotated;
        }

        return $image;
    }
}
