<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\RoundedCornersStyle;
use ImagePuzzle\PieceContext;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * G03: the cached corner mask was initialised fully transparent, so the whole
 * r x r corner square was erased; radius >= min/2 silently disabled rounding.
 *
 * Convention: a pixel (px, py) is evaluated at its centre (px + 0.5, py + 0.5).
 * For the top-left corner the arc centre is (r, r); a pixel is removed only
 * when its centre lies strictly outside the circle of radius r.
 */

function roundedContext(int $w, int $h): PieceContext
{
    return new PieceContext(0, 0, 0, 0, $w, $h, $w, $h, 1, 1);
}

function outsideArc(int $px, int $py, float $r): bool
{
    $dx = $px + 0.5 - $r;
    $dy = $py + 0.5 - $r;

    return $px + 0.5 < $r && $py + 0.5 < $r && $dx * $dx + $dy * $dy > $r * $r;
}

test('only pixels outside the arc are removed, on all four corners', function (int $radius): void {
    $w = 40;
    $h = 30;
    $image = Fixtures::coordinateImage($w, $h, withAlpha: false);
    (new RoundedCornersStyle($radius))->apply($image, roundedContext($w, $h));

    $removed = 0;
    for ($py = 0; $py < $h; $py++) {
        for ($px = 0; $px < $w; $px++) {
            // Mirror into the top-left quadrant.
            $mx = min($px, $w - 1 - $px);
            $my = min($py, $h - 1 - $py);
            $expectedRemoved = outsideArc($mx, $my, (float) $radius);
            $alpha = Fixtures::alpha($image, $px, $py);
            expect($alpha)->toBe($expectedRemoved ? 127 : 0, "pixel {$px},{$py} radius {$radius}");
            $removed += $expectedRemoved ? 1 : 0;
        }
    }
    // A quarter disc of radius r removes roughly (1 - pi/4) r^2 pixels per corner, never r^2.
    expect($removed)->toBeLessThan(4 * $radius * $radius)->toBeGreaterThan(0);
})->with([2, 4, 8, 12]);

test('retained corner pixels keep their source colour', function (): void {
    $image = Fixtures::coordinateImage(32, 32, withAlpha: false);
    (new RoundedCornersStyle(8))->apply($image, roundedContext(32, 32));
    // (7,7) is inside the arc of the top-left corner: centre distance to (8,8) is ~0.7.
    expect(Fixtures::rgba($image, 7, 7))->toBe(['r' => 7, 'g' => 7, 'b' => 0, 'a' => 0]);
    expect(Fixtures::alpha($image, 0, 0))->toBe(127);
});

test('pre-existing transparency is preserved inside the arc', function (): void {
    $image = Fixtures::coordinateImage(30, 30, withAlpha: true);
    $before = Fixtures::rgba($image, 6, 6);
    (new RoundedCornersStyle(6))->apply($image, roundedContext(30, 30));
    expect(Fixtures::rgba($image, 6, 6))->toBe($before);
});

test('radius larger than half the shorter side is clamped, not disabled', function (): void {
    $image = Fixtures::coordinateImage(20, 12, withAlpha: false);
    (new RoundedCornersStyle(50))->apply($image, roundedContext(20, 12));
    // Effective radius is 6 (half of 12): corners rounded, the centre stays opaque.
    expect(Fixtures::alpha($image, 0, 0))->toBe(127);
    expect(Fixtures::alpha($image, 19, 11))->toBe(127);
    expect(Fixtures::alpha($image, 10, 6))->toBe(0);
    expect((new RoundedCornersStyle(50))->effectiveRadius(20, 12))->toBe(6.0);
});

test('mask cache is bounded', function (): void {
    RoundedCornersStyle::clearMaskCache();
    for ($r = 1; $r <= RoundedCornersStyle::MAX_CACHED_MASKS + 10; $r++) {
        (new RoundedCornersStyle($r))->apply(Fixtures::canvas(200, 200), roundedContext(200, 200));
    }
    expect(RoundedCornersStyle::cachedMaskCount())->toBeLessThanOrEqual(RoundedCornersStyle::MAX_CACHED_MASKS);
});
