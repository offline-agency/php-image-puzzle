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
        $manifest = Fixtures::json($manifestPath);
        $pieces = Fixtures::pieces($manifest);
        $dir = dirname($manifestPath);
        $geometry = is_array($manifest['geometry'] ?? null) ? $manifest['geometry'] : [];
        // Only rectangular boards hold exactly rows x cols pieces.
        if (($geometry['topology'] ?? 'rect') === 'rect' && count($pieces) !== Fixtures::int($manifest, 'rows') * Fixtures::int($manifest, 'cols')) {
            throw new RuntimeException('Manifest piece count mismatch');
        }
        foreach ($pieces as $piece) {
            $file = $dir . DIRECTORY_SEPARATOR . $piece['filename'];
            [$width, $height] = Fixtures::size($file);
            if ($width !== $piece['rendered']['width'] || $height !== $piece['rendered']['height']) {
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
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $hash = sha1_file($file->getPathname());
                if ($hash === false) {
                    throw new RuntimeException("Cannot hash {$file->getPathname()}");
                }
                $out[substr($file->getPathname(), strlen($dir) + 1)] = $hash;
            }
        }
        ksort($out);

        return $out;
    }
}
