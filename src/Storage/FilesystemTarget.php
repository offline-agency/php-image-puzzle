<?php

declare(strict_types=1);

namespace ImagePuzzle\Storage;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\StorageException;
use ImagePuzzle\Support\ErrorCapture;

/**
 * A local directory. Each object is written to a temporary file next to it
 * and renamed into place, so readers never see a partial object.
 */
final class FilesystemTarget implements OutputTarget
{
    private readonly string $root;

    public function __construct(string $root)
    {
        if (!is_dir($root)) {
            throw new InvalidArgumentException("Storage root {$root} is not a directory.");
        }
        $real = realpath($root);
        if ($real === false) {
            throw new InvalidArgumentException("Storage root {$root} cannot be resolved.");
        }
        $this->root = $real;
    }

    public function write(string $key, string $contents): void
    {
        $this->commit($key, static fn ($handle): bool => fwrite($handle, $contents) === strlen($contents));
    }

    public function writeStream(string $key, $stream): void
    {
        $this->commit($key, static function ($handle) use ($stream): bool {
            if (stream_get_meta_data($stream)['seekable']) {
                rewind($stream);
            }

            return stream_copy_to_stream($stream, $handle) !== false;
        });
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function read(string $key): string
    {
        $path = $this->path($key);
        $contents = is_file($path) ? ErrorCapture::run(static fn (): string|false => file_get_contents($path)) : false;
        if ($contents === false) {
            throw new StorageException("Object {$key} does not exist or cannot be read.");
        }

        return $contents;
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path) && !ErrorCapture::run(static fn (): bool => unlink($path))) {
            throw new StorageException("Object {$key} cannot be deleted.");
        }
    }

    private function path(string $key): string
    {
        return $this->root . '/' . StorageKey::assert($key);
    }

    /**
     * @param \Closure(resource): bool $fill
     */
    private function commit(string $key, \Closure $fill): void
    {
        $path = $this->path($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !ErrorCapture::run(static fn (): bool => mkdir($dir, 0o755, true)) && !is_dir($dir)) {
            throw new StorageException("Cannot create the directory for {$key}.");
        }
        $temp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = ErrorCapture::run(static fn () => fopen($temp, 'xb'));
        if ($handle === false) {
            throw new StorageException("Cannot write {$key}.");
        }
        try {
            $ok = $fill($handle);
            $ok = fclose($handle) && $ok;
            if (!$ok || !ErrorCapture::run(static fn (): bool => rename($temp, $path))) {
                throw new StorageException("Cannot write {$key}.");
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
