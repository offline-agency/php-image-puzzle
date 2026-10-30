<?php

declare(strict_types=1);

namespace ImagePuzzle\Support;

/**
 * Directory-tree helpers that never follow symbolic links.
 *
 * @internal
 */
final class Tree
{
    /**
     * Regular files under $dir, relative with "/" separators, sorted. Links
     * and special files are reported in $other instead.
     *
     * @param list<string> $other
     * @return list<string>
     */
    public static function files(string $dir, array &$other = []): array
    {
        $out = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $relative = substr($item->getPathname(), strlen($dir) + 1);
            if ($item->isLink() || !$item->isFile()) {
                $other[] = $relative;
                continue;
            }
            $out[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        }
        sort($out);

        return $out;
    }

    /** Total size of the regular files under $dir. */
    public static function bytes(string $dir): int
    {
        $total = 0;
        foreach (self::files($dir) as $file) {
            $total += (int) filesize($dir . '/' . $file);
        }

        return $total;
    }

    /** Removes $path (a file, a link or a directory tree); missing paths are fine. */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
