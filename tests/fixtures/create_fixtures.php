<?php

declare(strict_types=1);

/**
 * One-time script to generate test fixture images. Run: php create_fixtures.php
 */

$dir = __DIR__;

// 32x32 PNG with transparency
$png = imagecreatetruecolor(32, 32);
if ($png === false) {
    throw new \RuntimeException('Failed to create PNG');
}
imagesavealpha($png, true);
imagealphablending($png, false);
$transparent = imagecolorallocatealpha($png, 0, 0, 0, 127);
imagefill($png, 0, 0, $transparent);
imagealphablending($png, true);
$red = imagecolorallocate($png, 255, 0, 0);
imagefilledrectangle($png, 4, 4, 28, 28, $red);
imagepng($png, $dir . '/sample.png', 9);
imagedestroy($png);
echo "Created sample.png (32x32 PNG with transparency)\n";

// 20x20 JPEG
$jpg = imagecreatetruecolor(20, 20);
if ($jpg === false) {
    throw new \RuntimeException('Failed to create JPEG');
}
$blue = imagecolorallocate($jpg, 0, 0, 255);
imagefill($jpg, 0, 0, $blue);
imagejpeg($jpg, $dir . '/sample.jpg', 90);
imagedestroy($jpg);
echo "Created sample.jpg (20x20 JPEG)\n";
