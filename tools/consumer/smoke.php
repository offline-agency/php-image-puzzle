<?php

declare(strict_types=1);

/*
 * Runs inside a throwaway consumer project that installed the package with
 * --no-dev (see run.sh). Uses only the public API.
 */

use ImagePuzzle\Config\PuzzleConfig;
use ImagePuzzle\PuzzleGenerator;

require getcwd() . '/vendor/autoload.php';

$dir = sys_get_temp_dir() . '/image-puzzle-consumer-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$image = imagecreatetruecolor(120, 90);
imagefilledrectangle($image, 0, 0, 119, 89, (int) imagecolorallocate($image, 30, 140, 90));
imagepng($image, $dir . '/source.png');

$config = PuzzleConfig::fromArray(['configVersion' => 2, 'rows' => 3, 'cols' => 4, 'edge' => ['type' => 'jigsaw']]);
$result = PuzzleGenerator::generateAndExport($dir . '/source.png', $dir . '/out', 3, 4, $config->edgeStyle(), $config->exportOptions(), geometry: $config->geometryOptions());
$pieces = count($result->getFiles()) - 1;

foreach (array_reverse($result->getFiles()) as $file) {
    unlink($file);
}
rmdir($dir . '/out');
unlink($dir . '/source.png');
rmdir($dir);

if ($pieces !== 12) {
    fwrite(STDERR, "expected 12 pieces, got {$pieces}\n");
    exit(1);
}
echo 'consumer smoke test passed (PHP ' . PHP_VERSION . ", {$pieces} pieces)\n";
