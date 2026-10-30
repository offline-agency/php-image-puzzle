<?php

declare(strict_types=1);

namespace ImagePuzzle\Storage;

use ImagePuzzle\Exception\StorageException;

/**
 * Where published puzzle files go: a local directory, memory, or an object
 * store through an adapter. Keys are relative "/"-separated paths checked by
 * StorageKey; a key never reaches the backend unchecked.
 *
 * Writing one object is atomic for readers of that object (they see the old
 * or the new contents, never a part), but nothing makes several objects
 * appear together. Publisher builds that guarantee on top: immutable
 * generation keys first, one reference object last.
 */
interface OutputTarget
{
    public function write(string $key, string $contents): void;

    /**
     * Copies the whole stream, from its start (seekable streams are rewound,
     * as Flysystem does). The stream is not closed.
     *
     * @param resource $stream
     */
    public function writeStream(string $key, $stream): void;

    public function exists(string $key): bool;

    /**
     * @throws StorageException when the object does not exist or cannot be read
     */
    public function read(string $key): string;

    /** Removes the object; removing a missing object is not an error. */
    public function delete(string $key): void;
}
