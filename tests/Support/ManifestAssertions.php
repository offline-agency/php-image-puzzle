<?php

declare(strict_types=1);

namespace ImagePuzzle\Tests\Support;

use RuntimeException;

/**
 * Checks that a published manifest describes complete, correctly named and
 * decodable piece files whose sizes match the rendered bounds.
 */
final class ManifestAssertions
{
    /**
     * @return array<string, mixed> decoded manifest
     */
    public static function assertConsistent(string $manifestPath): array
    {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || !is_array($manifest['pieces'] ?? null)) {
            throw new RuntimeException('Manifest has no pieces');
        }
        $dir = dirname($manifestPath);
        $expected = (int) $manifest['rows'] * (int) $manifest['cols'];
        if (count($manifest['pieces']) !== $expected) {
            throw new RuntimeException('Manifest piece count mismatch');
        }
        foreach ($manifest['pieces'] as $piece) {
            $file = $dir . DIRECTORY_SEPARATOR . $piece['filename'];
            $size = @getimagesize($file);
            if ($size === false) {
                throw new RuntimeException("Undecodable piece {$file}");
            }
            if ($size[0] !== $piece['rendered']['width'] || $size[1] !== $piece['rendered']['height']) {
                throw new RuntimeException("Piece {$file} has wrong dimensions");
            }
        }

        return $manifest;
    }

    /**
     * @return array<string, string> filename => sha1 of every regular file in $dir (recursive, relative names)
     */
    public static function snapshot(string $dir): array
    {
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile()) {
                $out[substr($file->getPathname(), strlen($dir) + 1)] = (string) sha1_file($file->getPathname());
            }
        }
        ksort($out);

        return $out;
    }
}
