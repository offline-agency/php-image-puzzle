<?php

declare(strict_types=1);

namespace ImagePuzzle\Storage;

use ImagePuzzle\Exception\StorageException;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Any Flysystem 3 filesystem: local, in-memory, or S3-compatible object
 * storage (league/flysystem-aws-s3-v3). Optional: the core never requires
 * league/flysystem; install it to use this class.
 *
 * On object stores, a write replaces the whole object, but there is no
 * rename and no transaction across objects: publish through Publisher,
 * which only ever writes identical bytes under a generation key and writes
 * the reference last.
 */
final class FlysystemTarget implements OutputTarget
{
    /**
     * @param array<string, mixed> $config passed to every write (visibility, ACL, cache headers)
     */
    public function __construct(
        private readonly FilesystemOperator $filesystem,
        private readonly array $config = [],
    ) {
    }

    public function write(string $key, string $contents): void
    {
        $this->call($key, fn () => $this->filesystem->write(StorageKey::assert($key), $contents, $this->config));
    }

    public function writeStream(string $key, $stream): void
    {
        $this->call($key, fn () => $this->filesystem->writeStream(StorageKey::assert($key), $stream, $this->config));
    }

    public function exists(string $key): bool
    {
        return $this->call($key, fn (): bool => $this->filesystem->fileExists(StorageKey::assert($key)));
    }

    public function read(string $key): string
    {
        return $this->call($key, fn (): string => $this->filesystem->read(StorageKey::assert($key)));
    }

    public function delete(string $key): void
    {
        $this->call($key, fn () => $this->filesystem->delete(StorageKey::assert($key)));
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function call(string $key, \Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (FilesystemException $e) {
            throw new StorageException("Storage operation on {$key} failed: {$e->getMessage()}", 0, $e);
        }
    }
}
