<?php

declare(strict_types=1);

use ImagePuzzle\EdgeStyle\ClassicEdgeHelper;
use ImagePuzzle\PieceContext;

test('applyProfiles sets expected edge pixels to transparent', function (): void {
    $width = 8;
    $height = 8;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    imagealphablending($image, false);
    $opaque = imagecolorallocatealpha($image, 100, 100, 100, 0);
    if ($opaque !== false) {
        imagefill($image, 0, 0, $opaque);
    }
    imagealphablending($image, true);

    $context = new PieceContext(0, 0, 0, 0, $width, $height, 32, 32, 2, 2);

    $profileForEdge = static function (int $length, bool $isTab): array {
        if ($length === 8 && !$isTab) {
            return [4 => true];
        }
        return [];
    };

    ClassicEdgeHelper::applyProfiles($image, $context, $profileForEdge);

    imagesavealpha($image, true);
    $alphaAt = static function (GdImage $img, int $x, int $y): int {
        $c = imagecolorat($img, $x, $y);
        return ($c >> 24) & 0x7F;
    };

    expect($alphaAt($image, 4, 0))->toBe(127);
    expect($alphaAt($image, 0, 4))->toBe(127);
});

test('applyProfiles with tab profile sets bottom and right edge pixels', function (): void {
    $width = 6;
    $height = 6;
    $image = imagecreatetruecolor($width, $height);
    expect($image)->not->toBeFalse();
    imagesavealpha($image, true);
    imagealphablending($image, false);
    $opaque = imagecolorallocatealpha($image, 50, 50, 50, 0);
    if ($opaque !== false) {
        imagefill($image, 0, 0, $opaque);
    }
    imagealphablending($image, true);

    $context = new PieceContext(0, 0, 0, 0, $width, $height, 24, 24, 2, 2);

    $profileForEdge = static function (int $length, bool $isTab): array {
        if ($isTab) {
            return [0 => true, $length - 1 => true];
        }
        return [];
    };

    ClassicEdgeHelper::applyProfiles($image, $context, $profileForEdge);

    imagesavealpha($image, true);
    $alphaAt = static function (GdImage $img, int $x, int $y): int {
        $c = imagecolorat($img, $x, $y);
        return ($c >> 24) & 0x7F;
    };

    expect($alphaAt($image, 0, $height - 1))->toBe(127);
    expect($alphaAt($image, $width - 1, 0))->toBe(127);
});
