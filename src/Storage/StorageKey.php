<?php

declare(strict_types=1);

namespace ImagePuzzle\Storage;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Object keys: relative paths of safe segments (letters, digits, ".", "_",
 * "-", not starting with "."), joined by "/". No empty, "." or ".." segment,
 * no backslash and no leading slash, so a key can never address something
 * outside the target's root on any backend.
 */
final class StorageKey
{
    public const MAX_LENGTH = 512;
    private const PATTERN = '~^[A-Za-z0-9_][A-Za-z0-9._-]*(/[A-Za-z0-9_][A-Za-z0-9._-]*)*$~';

    public static function assert(string $key): string
    {
        if (strlen($key) > self::MAX_LENGTH || preg_match(self::PATTERN, $key) !== 1) {
            throw new InvalidArgumentException('Invalid storage key "' . substr($key, 0, 80) . '": use relative "/"-separated segments of letters, digits, ".", "_" and "-" that do not start with ".".');
        }

        return $key;
    }

    /** Joins a prefix ("" for none) and a relative key. */
    public static function join(string $prefix, string $key): string
    {
        return self::assert($prefix === '' ? $key : rtrim($prefix, '/') . '/' . $key);
    }
}
