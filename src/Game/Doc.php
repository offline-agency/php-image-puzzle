<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidDefinitionException;

/**
 * Narrowing helpers for decoded JSON documents (definitions, states and
 * actions are external data). Every failure names the JSON path.
 *
 * @internal
 */
final class Doc
{
    public const ID_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    /**
     * @return array<string, mixed>
     */
    public static function object(mixed $value, string $path): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidDefinitionException("{$path} must be an object.");
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $value, string $path, int $max = 4096): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidDefinitionException("{$path} must be an array.");
        }
        if (count($value) > $max) {
            throw new InvalidDefinitionException("{$path} has more than {$max} entries.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string> $allowed
     */
    public static function keys(array $object, array $allowed, string $path): void
    {
        $unknown = array_diff(array_keys($object), $allowed);
        if ($unknown !== []) {
            throw new InvalidDefinitionException("{$path} has unknown field(s): " . implode(', ', $unknown) . '.');
        }
    }

    /**
     * @param array<string, mixed> $object
     */
    public static function int(array $object, string $key, string $path, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        if (!array_key_exists($key, $object)) {
            throw new InvalidDefinitionException("{$path}.{$key} is required.");
        }

        return self::intValue($object[$key], "{$path}.{$key}", $min, $max);
    }

    public static function intValue(mixed $value, string $path, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        if (!is_int($value)) {
            throw new InvalidDefinitionException("{$path} must be an integer.");
        }
        if ($value < $min || $value > $max) {
            throw new InvalidDefinitionException("{$path} must be between {$min} and {$max}, got {$value}.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $object
     * @return positive-int
     */
    public static function positive(array $object, string $key, string $path, int $max = PHP_INT_MAX): int
    {
        $value = self::int($object, $key, $path, 1, $max);

        return self::positiveValue($value, "{$path}.{$key}");
    }

    /**
     * @return positive-int
     */
    public static function positiveValue(int $value, string $path): int
    {
        if ($value < 1) {
            throw new InvalidDefinitionException("{$path} must be positive.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $object
     */
    public static function string(array $object, string $key, string $path, int $maxLength = 2048): string
    {
        if (!array_key_exists($key, $object)) {
            throw new InvalidDefinitionException("{$path}.{$key} is required.");
        }

        return self::stringValue($object[$key], "{$path}.{$key}", $maxLength);
    }

    public static function stringValue(mixed $value, string $path, int $maxLength = 2048): string
    {
        if (!is_string($value)) {
            throw new InvalidDefinitionException("{$path} must be a string.");
        }
        if (strlen($value) > $maxLength) {
            throw new InvalidDefinitionException("{$path} is longer than {$maxLength} bytes.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $object
     */
    public static function bool(array $object, string $key, string $path): bool
    {
        if (!array_key_exists($key, $object) || !is_bool($object[$key])) {
            throw new InvalidDefinitionException("{$path}.{$key} must be a boolean.");
        }

        return $object[$key];
    }

    public static function id(mixed $value, string $path): string
    {
        $id = self::stringValue($value, $path, 32);
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidDefinitionException("{$path} is not a valid identifier: \"{$id}\".");
        }

        return $id;
    }
}
