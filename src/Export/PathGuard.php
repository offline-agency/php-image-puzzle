<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use ImagePuzzle\Exception\ExportFailedException;

/**
 * Validates and creates output directories.
 *
 * With a base directory, the candidate is checked BEFORE anything is created:
 * the deepest existing ancestor is resolved with realpath() (following any
 * symlinks) and must lie inside the resolved base; each missing component is
 * then created one level at a time and re-checked. "." and ".." are not
 * accepted in missing components.
 *
 * Trust model: this rejects misconfiguration, traversal and symlinks that exist
 * when the export starts. It is not a race-proof sandbox against a local
 * attacker who can rename or replace directories inside the output tree while
 * an export runs (PHP has no openat()/O_NOFOLLOW directory API). Run exports in
 * directories that untrusted users cannot write to.
 */
final class PathGuard
{
    public static function prepare(string $directory, ?string $baseDirectory): string
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '') {
            throw new ExportFailedException('Output directory cannot be empty.');
        }
        if (str_contains($directory, "\0")) {
            throw new ExportFailedException('Output directory must not contain NUL bytes.');
        }

        if ($baseDirectory === null) {
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new ExportFailedException("Failed to create directory: {$directory}");
            }
            $real = realpath($directory);
            if ($real === false || !is_dir($real)) {
                throw new ExportFailedException("Output directory could not be resolved: {$directory}");
            }
            self::assertWritable($real, $directory);

            return $real;
        }

        $base = realpath($baseDirectory);
        if ($base === false || !is_dir($base)) {
            throw new ExportFailedException("Base directory does not exist or is not a directory: {$baseDirectory}");
        }

        $absolute = self::isAbsolute($directory) ? $directory : getcwd() . DIRECTORY_SEPARATOR . $directory;
        $parts = preg_split('#[/\\\\]+#', $absolute, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $prefix = self::rootOf($absolute);

        // Find the deepest existing ancestor.
        $existing = $prefix;
        $index = 0;
        foreach ($parts as $i => $part) {
            $candidate = $existing . ($existing === '' || str_ends_with($existing, DIRECTORY_SEPARATOR) ? '' : DIRECTORY_SEPARATOR) . $part;
            if (!file_exists($candidate) && !is_link($candidate)) {
                break;
            }
            $existing = $candidate;
            $index = $i + 1;
        }
        $resolved = realpath($existing === '' ? DIRECTORY_SEPARATOR : $existing);
        if ($resolved === false || !is_dir($resolved)) {
            throw new ExportFailedException("Output directory could not be resolved: {$directory}");
        }
        self::assertInside($resolved, $base, $directory);

        // Create the missing components one by one.
        foreach (array_slice($parts, $index) as $part) {
            if ($part === '.' || $part === '..') {
                throw new ExportFailedException("Output directory must not traverse with '.' or '..': {$directory}");
            }
            $next = $resolved . DIRECTORY_SEPARATOR . $part;
            if (!@mkdir($next, 0755) && !is_dir($next)) {
                throw new ExportFailedException("Failed to create directory: {$next}");
            }
            if (is_link($next)) {
                throw new ExportFailedException("Output directory component is a symbolic link: {$next}");
            }
            $real = realpath($next);
            if ($real === false) {
                throw new ExportFailedException("Output directory could not be resolved: {$next}");
            }
            self::assertInside($real, $base, $directory);
            $resolved = $real;
        }
        self::assertWritable($resolved, $directory);

        return $resolved;
    }

    private static function assertInside(string $resolved, string $base, string $original): void
    {
        if ($resolved !== $base && !str_starts_with($resolved, rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new ExportFailedException("Output directory must be inside base directory: {$original}");
        }
    }

    private static function assertWritable(string $real, string $original): void
    {
        if (!is_writable($real)) {
            throw new ExportFailedException("Directory is not writable: {$original}");
        }
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }

    private static function rootOf(string $absolute): string
    {
        if (preg_match('#^([A-Za-z]:)[/\\\\]#', $absolute, $m) === 1) {
            return $m[1] . DIRECTORY_SEPARATOR;
        }

        return DIRECTORY_SEPARATOR;
    }
}
