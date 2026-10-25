<?php

declare(strict_types=1);

use ImagePuzzle\Exception\UnsupportedImageTypeException;
use ImagePuzzle\ImageLoader;
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * G17: EXIF orientation, animated GIFs and WebP input. The orientation oracle
 * maps every displayed pixel back to the stored pixel with the formulas of
 * the EXIF specification and compares quadrant colours.
 */

/**
 * JPEG of $w x $h with four solid quadrants (red, green / blue, white) and an
 * EXIF APP1 segment carrying $orientation in the given byte order.
 */
function orientedJpeg(string $path, int $w, int $h, int $orientation, bool $littleEndian): string
{
    $image = Fixtures::canvas($w, $h);
    $half = [intdiv($w, 2), intdiv($h, 2)];
    imagefilledrectangle($image, 0, 0, $half[0] - 1, $half[1] - 1, Fixtures::color($image, 255, 0, 0));
    imagefilledrectangle($image, $half[0], 0, $w - 1, $half[1] - 1, Fixtures::color($image, 0, 255, 0));
    imagefilledrectangle($image, 0, $half[1], $half[0] - 1, $h - 1, Fixtures::color($image, 0, 0, 255));
    imagefilledrectangle($image, $half[0], $half[1], $w - 1, $h - 1, Fixtures::color($image, 255, 255, 255));
    ob_start();
    imagejpeg($image, null, 100);
    $jpeg = (string) ob_get_clean();

    $u16 = static fn (int $v): string => pack($littleEndian ? 'v' : 'n', $v);
    $u32 = static fn (int $v): string => pack($littleEndian ? 'V' : 'N', $v);
    $tiff = ($littleEndian ? 'II' : 'MM') . $u16(42) . $u32(8)
        . $u16(1) . $u16(0x0112) . $u16(3) . $u32(1) . $u16($orientation) . $u16(0)
        . $u32(0);
    $payload = "Exif\0\0" . $tiff;
    $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    file_put_contents($path, substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2));

    return $path;
}

/**
 * Stored coordinates shown at displayed ($dx, $dy) for an EXIF orientation.
 *
 * @return array{0: int, 1: int}
 */
function storedPixel(int $orientation, int $dx, int $dy, int $w, int $h): array
{
    return match ($orientation) {
        1 => [$dx, $dy],
        2 => [$w - 1 - $dx, $dy],
        3 => [$w - 1 - $dx, $h - 1 - $dy],
        4 => [$dx, $h - 1 - $dy],
        5 => [$dy, $dx],
        6 => [$dy, $h - 1 - $dx],
        7 => [$w - 1 - $dy, $h - 1 - $dx],
        8 => [$w - 1 - $dy, $dx],
        default => throw new InvalidArgumentException('orientation'),
    };
}

/**
 * @return array{0: int, 1: int, 2: int} quadrant colour of a stored pixel
 */
function quadrantColor(int $x, int $y, int $w, int $h): array
{
    $right = $x >= intdiv($w, 2);
    $bottom = $y >= intdiv($h, 2);

    if ($bottom) {
        return $right ? [255, 255, 255] : [0, 0, 255];
    }

    return $right ? [0, 255, 0] : [255, 0, 0];
}

test('EXIF orientation is applied exactly when requested', function (int $orientation, bool $little): void {
    $dir = Fixtures::tempDir('exif');
    try {
        [$w, $h] = [40, 24];
        $path = orientedJpeg($dir . '/o.jpg', $w, $h, $orientation, $little);
        $loader = new ImageLoader(applyExifOrientation: true);
        $info = $loader->inspect($path);
        expect($info->orientation)->toBe($orientation);
        $image = $loader->loadFromFile($path);
        $swap = $orientation >= 5;
        expect([imagesx($image), imagesy($image)])->toBe($swap ? [$h, $w] : [$w, $h]);
        expect([$info->outputWidth(), $info->outputHeight()])->toBe([imagesx($image), imagesy($image)]);
        // Sample the centre of each displayed quadrant (JPEG noise stays far from the borders).
        [$dw, $dh] = [imagesx($image), imagesy($image)];
        foreach ([[$dw / 4, $dh / 4], [3 * $dw / 4, $dh / 4], [$dw / 4, 3 * $dh / 4], [3 * $dw / 4, 3 * $dh / 4]] as [$fx, $fy]) {
            [$dx, $dy] = [(int) $fx, (int) $fy];
            [$sx, $sy] = storedPixel($orientation, $dx, $dy, $w, $h);
            $expected = quadrantColor($sx, $sy, $w, $h);
            $actual = Fixtures::rgba($image, $dx, $dy);
            expect(abs($actual['r'] - $expected[0]) + abs($actual['g'] - $expected[1]) + abs($actual['b'] - $expected[2]))
                ->toBeLessThan(30, "orientation {$orientation} at {$dx},{$dy}");
        }
    } finally {
        Fixtures::removeDir($dir);
    }
})->with([1, 2, 3, 4, 5, 6, 7, 8])->with(['little-endian' => [true], 'big-endian' => [false]]);

test('orientation metadata is ignored by default and never affects grid validation', function (): void {
    $dir = Fixtures::tempDir('exif-default');
    try {
        // Stored 20 wide x 40 tall; orientation 6 would display it as 40x20.
        $path = orientedJpeg($dir . '/o.jpg', 20, 40, 6, true);
        $loader = new ImageLoader();
        $info = $loader->inspect($path);
        expect($info->orientation)->toBe(6);
        expect([$info->outputWidth(), $info->outputHeight()])->toBe([20, 40]);
        expect([imagesx($loader->loadFromFile($path)), imagesy($loader->loadFromFile($path))])->toBe([20, 40]);
        // 30 rows fit the 40 stored rows; validating against swapped axes would reject this.
        $puzzle = (new PuzzleGenerator())->generateFromFile($path, 30, 2);
        expect($puzzle->countPieces())->toBe(60);
    } finally {
        Fixtures::removeDir($dir);
    }
});

test('with orientation applied, the grid is validated against the displayed size', function (): void {
    $dir = Fixtures::tempDir('exif-applied');
    try {
        $path = orientedJpeg($dir . '/o.jpg', 20, 40, 6, true);
        $generator = new PuzzleGenerator(imageLoader: new ImageLoader(applyExifOrientation: true));
        $puzzle = $generator->generateFromFile($path, 2, 30);
        expect([$puzzle->getWidth(), $puzzle->getHeight()])->toBe([40, 20]);
        expect(fn () => $generator->generateFromFile($path, 30, 2))->toThrow(ImagePuzzle\Exception\InvalidArgumentException::class);
    } finally {
        Fixtures::removeDir($dir);
    }
});

/**
 * Minimal 1x1 GIF with $frames image blocks.
 */
function gifWithFrames(int $frames): string
{
    $frame = "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00" . "\x02\x02\x44\x01\x00";
    $comment = "\x21\xFE\x03abc\x00";

    return 'GIF89a' . "\x01\x00\x01\x00\x80\x00\x00" . "\x00\x00\x00\xFF\xFF\xFF" . $comment . str_repeat($frame, $frames) . "\x3B";
}

test('animated GIFs are rejected unless the first frame is explicitly accepted', function (): void {
    $dir = Fixtures::tempDir('gif');
    try {
        file_put_contents($dir . '/still.gif', gifWithFrames(1));
        file_put_contents($dir . '/anim.gif', gifWithFrames(3));
        $loader = new ImageLoader();
        expect($loader->inspect($dir . '/still.gif')->frames)->toBe(1);
        expect($loader->inspect($dir . '/anim.gif')->frames)->toBe(3);
        expect($loader->loadFromFile($dir . '/still.gif'))->toBeInstanceOf(GdImage::class);
        expect(fn () => $loader->loadFromFile($dir . '/anim.gif'))
            ->toThrow(UnsupportedImageTypeException::class, 'Animated GIF with 3 frames');
        $image = (new ImageLoader(allowAnimatedFirstFrame: true))->loadFromFile($dir . '/anim.gif');
        expect([imagesx($image), imagesy($image)])->toBe([1, 1]);
    } finally {
        Fixtures::removeDir($dir);
    }
});

test('WebP input is decoded when GD supports it', function (): void {
    $dir = Fixtures::tempDir('webp');
    try {
        $source = Fixtures::coordinateImage(30, 20, false);
        imagewebp($source, $dir . '/s.webp', 100);
        $loader = new ImageLoader();
        expect($loader->inspect($dir . '/s.webp')->type)->toBe('webp');
        $image = $loader->loadFromFile($dir . '/s.webp');
        expect([imagesx($image), imagesy($image)])->toBe([30, 20]);
    } finally {
        Fixtures::removeDir($dir);
    }
})->skip(!(ImageLoader::decoders()['webp'] ?? false), 'GD without WebP support');

test('signature and header mismatches are rejected', function (): void {
    $dir = Fixtures::tempDir('mismatch');
    try {
        // A PNG signature followed by a GIF body.
        file_put_contents($dir . '/fake.png', "\x89PNG\r\n\x1A\n" . gifWithFrames(1));
        expect(fn () => (new ImageLoader())->loadFromFile($dir . '/fake.png'))->toThrow(UnsupportedImageTypeException::class);
        file_put_contents($dir . '/empty.png', '');
        expect(fn () => (new ImageLoader())->inspect($dir . '/empty.png'))->toThrow(UnsupportedImageTypeException::class, 'empty');
    } finally {
        Fixtures::removeDir($dir);
    }
});
