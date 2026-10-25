<?php

declare(strict_types=1);

namespace ImagePuzzle\Tests\Support;

use GdImage;
use RuntimeException;

/**
 * Coordinate-rich fixtures used as geometry oracles, plus typed accessors
 * for files and JSON written by the library.
 *
 * Every pixel of a coordinate image has a unique RGB triple for images up to
 * 4096x4096, so a misplaced, duplicated or lost pixel is detectable. The left
 * third optionally carries pre-existing partial transparency.
 *
 * @phpstan-type PieceRecord array{id: string, row: int, col: int, x: int, y: int, width: int, height: int, filename: string, rendered: array{x: int, y: int, width: int, height: int}, path?: string}
 */
final class Fixtures
{
    public static function coordinateImage(int $width, int $height, bool $withAlpha = true): GdImage
    {
        $image = self::canvas($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $alpha = $withAlpha && $x < intdiv($width, 3) ? (($x + $y) % 4) * 30 : 0;
                [$r, $g, $b] = self::expectedRgb($x, $y);
                imagesetpixel($image, $x, $y, self::color($image, $r, $g, $b, $alpha));
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
        $c = self::pixel($image, $x, $y);

        return ['r' => ($c >> 16) & 0xFF, 'g' => ($c >> 8) & 0xFF, 'b' => $c & 0xFF, 'a' => ($c >> 24) & 0x7F];
    }

    public static function alpha(GdImage $image, int $x, int $y): int
    {
        return (self::pixel($image, $x, $y) >> 24) & 0x7F;
    }

    public static function pixel(GdImage $image, int $x, int $y): int
    {
        $c = imagecolorat($image, $x, $y);
        if ($c === false) {
            throw new RuntimeException("Cannot read pixel {$x},{$y}");
        }

        return $c;
    }

    /**
     * Blank truecolor image; rejects non-positive sizes.
     */
    public static function canvas(int $width, int $height): GdImage
    {
        if ($width < 1 || $height < 1) {
            throw new RuntimeException("Invalid canvas size {$width}x{$height}");
        }

        return imagecreatetruecolor($width, $height);
    }

    public static function color(GdImage $image, int $r, int $g, int $b, int $alpha = 0): int
    {
        if ($r < 0 || $r > 255 || $g < 0 || $g > 255 || $b < 0 || $b > 255 || $alpha < 0 || $alpha > 127) {
            throw new RuntimeException("Invalid colour {$r},{$g},{$b},{$alpha}");
        }
        $color = imagecolorallocatealpha($image, $r, $g, $b, $alpha);
        if ($color === false) {
            throw new RuntimeException("Cannot allocate colour {$r},{$g},{$b},{$alpha}");
        }

        return $color;
    }

    /**
     * Load an image file written by the library.
     */
    public static function load(string $path): GdImage
    {
        $data = file_get_contents($path);
        $image = $data === false || $data === '' ? false : imagecreatefromstring($data);
        if ($image === false) {
            throw new RuntimeException("Cannot decode image {$path}");
        }

        return $image;
    }

    /**
     * @return array{0: positive-int, 1: positive-int}
     */
    public static function size(string $path): array
    {
        $info = @getimagesize($path);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException("Cannot read image size of {$path}");
        }

        return [$info[0], $info[1]];
    }

    /**
     * Decode a JSON object file.
     *
     * @return array<string, mixed>
     */
    public static function json(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        return self::object(json_decode($raw, true, 64, JSON_THROW_ON_ERROR), $path);
    }

    /**
     * @return array<string, mixed>
     */
    public static function object(mixed $value, string $what = 'value'): array
    {
        if (!is_array($value)) {
            throw new RuntimeException("{$what} is not a JSON object");
        }
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }

    /**
     * Piece records of a decoded manifest, shape-checked.
     *
     * @param array<string, mixed> $manifest
     * @return list<PieceRecord>
     */
    public static function pieces(array $manifest): array
    {
        if (!is_array($manifest['pieces'] ?? null)) {
            throw new RuntimeException('Manifest has no pieces');
        }
        $out = [];
        foreach ($manifest['pieces'] as $i => $piece) {
            if (!is_array($piece) || !is_array($piece['rendered'] ?? null)) {
                throw new RuntimeException("Piece #{$i} is malformed");
            }
            $r = $piece['rendered'];
            $record = [
                'id' => self::str($piece, 'id'),
                'row' => self::int($piece, 'row'),
                'col' => self::int($piece, 'col'),
                'x' => self::int($piece, 'x'),
                'y' => self::int($piece, 'y'),
                'width' => self::int($piece, 'width'),
                'height' => self::int($piece, 'height'),
                'filename' => self::str($piece, 'filename'),
                'rendered' => ['x' => self::int($r, 'x'), 'y' => self::int($r, 'y'), 'width' => self::int($r, 'width'), 'height' => self::int($r, 'height')],
            ];
            if (array_key_exists('path', $piece)) {
                $record['path'] = self::str($piece, 'path');
            }
            $out[] = $record;
        }

        return $out;
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException("{$key} is not an integer");
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    public static function str(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("{$key} is not a string");
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public static function glob(string $pattern): array
    {
        $matches = glob($pattern);

        return $matches === false ? [] : $matches;
    }

    public static function realpath(string $path): string
    {
        $real = realpath($path);
        if ($real === false) {
            throw new RuntimeException("Cannot resolve {$path}");
        }

        return $real;
    }

    public static function sha256(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException("Cannot hash {$path}");
        }

        return $hash;
    }

    public static function pid(): int
    {
        $pid = getmypid();
        if ($pid === false) {
            throw new RuntimeException('Cannot read the process id');
        }

        return $pid;
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
        $real = realpath($dir);
        if ($real === false) {
            throw new RuntimeException("Cannot resolve {$dir}");
        }

        return $real;
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
        $entries = scandir($dir);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeDir($dir . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($dir);
    }
}
