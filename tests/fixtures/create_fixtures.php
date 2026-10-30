<?php

declare(strict_types=1);

/**
 * One-time script to generate test fixture images. Run: php create_fixtures.php
 */

$dir = __DIR__;

$allocate = static function (GdImage $image, int $r, int $g, int $b, int $alpha = 0): int {
    if ($r < 0 || $r > 255 || $g < 0 || $g > 255 || $b < 0 || $b > 255 || $alpha < 0 || $alpha > 127) {
        throw new RuntimeException('Invalid colour');
    }
    $color = imagecolorallocatealpha($image, $r, $g, $b, $alpha);
    if ($color === false) {
        throw new RuntimeException('Failed to allocate colour');
    }

    return $color;
};

// 32x32 PNG with transparency
$png = imagecreatetruecolor(32, 32);
imagesavealpha($png, true);
imagealphablending($png, false);
imagefill($png, 0, 0, $allocate($png, 0, 0, 0, 127));
imagealphablending($png, true);
imagefilledrectangle($png, 4, 4, 28, 28, $allocate($png, 255, 0, 0));
imagepng($png, $dir . '/sample.png', 9);
echo "Created sample.png (32x32 PNG with transparency)\n";

// 20x20 JPEG
$jpg = imagecreatetruecolor(20, 20);
imagefill($jpg, 0, 0, $allocate($jpg, 0, 0, 255));
imagejpeg($jpg, $dir . '/sample.jpg', 90);
echo "Created sample.jpg (20x20 JPEG)\n";
