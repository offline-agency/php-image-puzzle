<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

/**
 * Canonical JSON used for content-bound identifiers: object keys sorted by
 * byte order at every depth, lists kept in order, no insignificant
 * whitespace, unescaped slashes and Unicode.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function digest(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function sort(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
            if ($value === []) {
                return new \stdClass();
            }
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::sort(...), $value);
    }
}
