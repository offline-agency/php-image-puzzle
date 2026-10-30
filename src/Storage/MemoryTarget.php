<?php

declare(strict_types=1);

namespace ImagePuzzle\Storage;

use ImagePuzzle\Exception\StorageException;

/**
 * Objects held in php://temp streams (spilling to a temporary file past
 * 2 MiB): for tests, previews and hosts that stream the result elsewhere.
 * Lives as long as the instance.
 */
final class MemoryTarget implements OutputTarget
{
    /** @var array<string, resource> */
    private array $objects = [];

    public function write(string $key, string $contents): void
    {
        $stream = self::open();
        fwrite($stream, $contents);
        $this->store($key, $stream);
    }

    public function writeStream(string $key, $stream): void
    {
        $copy = self::open();
        if (stream_get_meta_data($stream)['seekable']) {
            rewind($stream);
        }
        if (stream_copy_to_stream($stream, $copy) === false) {
            fclose($copy);
            throw new StorageException("Cannot write {$key}.");
        }
        $this->store($key, $copy);
    }

    public function exists(string $key): bool
    {
        return isset($this->objects[StorageKey::assert($key)]);
    }

    public function read(string $key): string
    {
        $stream = $this->objects[StorageKey::assert($key)] ?? throw new StorageException("Object {$key} does not exist.");
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * Opens an object for reading from its start, or null when it is missing.
     *
     * @return resource|null
     */
    public function stream(string $key)
    {
        $stream = $this->objects[StorageKey::assert($key)] ?? null;
        if ($stream !== null) {
            rewind($stream);
        }

        return $stream;
    }

    public function delete(string $key): void
    {
        $key = StorageKey::assert($key);
        if (isset($this->objects[$key])) {
            fclose($this->objects[$key]);
            unset($this->objects[$key]);
        }
    }

    /**
     * @return list<string> sorted keys
     */
    public function keys(): array
    {
        $keys = array_map(strval(...), array_keys($this->objects));
        sort($keys);

        return $keys;
    }

    /**
     * @param resource $stream
     */
    private function store(string $key, $stream): void
    {
        $key = StorageKey::assert($key);
        $this->delete($key);
        $this->objects[$key] = $stream;
    }

    /**
     * @return resource
     */
    private static function open()
    {
        $stream = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($stream === false) {
            throw new StorageException('Cannot open a temporary stream.');
        }

        return $stream;
    }
}
