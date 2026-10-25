<?php

declare(strict_types=1);

namespace ImagePuzzle\Support;

/**
 * Runs native calls that report problems through PHP warnings (GD decoders,
 * getimagesize, filesystem functions) and collects those warnings instead of
 * emitting them, so callers can turn them into package exceptions.
 *
 * @internal
 */
final class ErrorCapture
{
    /**
     * @template T
     * @param callable(): T $fn
     * @param list<string> $warnings receives the captured messages
     * @return T
     */
    public static function run(callable $fn, array &$warnings = []): mixed
    {
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE);
        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}
