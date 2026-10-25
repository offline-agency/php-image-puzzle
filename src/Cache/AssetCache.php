<?php

declare(strict_types=1);

namespace ImagePuzzle\Cache;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\StorageException;
use ImagePuzzle\Game\CanonicalJson;
use ImagePuzzle\Support\ErrorCapture;
use ImagePuzzle\Support\Tree;

/**
 * Local cache of generated, immutable puzzle assets (definition.json and the
 * files it references), keyed by CacheKey. It never holds player state:
 * saved games belong to the browser or the host application.
 *
 * Layout under the root:
 *
 *     entries/<2 hex>/<digest>/entry.json   key material, file list, hashes
 *     entries/<2 hex>/<digest>/payload/...  the generated files
 *     staging/   in-progress builds        locks/   one lock file per key
 *
 * A build runs in staging and is renamed into entries/ only when complete,
 * so readers never see a partial entry. Producers of one key are serialised
 * with an exclusive lock; the second producer finds the first one's entry.
 * Every hit is validated (key material, exact file set, SHA-256 of each
 * file); an invalid entry is removed and rebuilt. prune() bounds the total
 * size (least recently used first) and age, and clears abandoned staging.
 *
 * Entries are shared and immutable: copy or publish from them, never modify
 * them in place.
 */
final class AssetCache
{
    public const ENTRY_FORMAT = 'image-puzzle/cache-entry';
    public const ENTRY_VERSION = 1;
    public const ENTRY_FILE = 'entry.json';
    public const PAYLOAD_DIR = 'payload';
    /** Staging directories older than this are abandoned builds. */
    private const STALE_STAGING_SECONDS = 3600;

    private readonly string $root;

    /**
     * @param int $maxBytes size bound applied after every new entry (0 disables automatic pruning)
     */
    public function __construct(string $root, private readonly int $maxBytes = 1 << 30)
    {
        if ($maxBytes < 0) {
            throw new InvalidArgumentException('The cache size bound cannot be negative.');
        }
        foreach (['', '/entries', '/staging', '/locks'] as $sub) {
            $dir = $root . $sub;
            if (!is_dir($dir) && !ErrorCapture::run(static fn (): bool => mkdir($dir, 0o755, true)) && !is_dir($dir)) {
                throw new StorageException("Cannot create the cache directory {$dir}.");
            }
        }
        $real = realpath($root);
        if ($real === false) {
            throw new StorageException("Cannot resolve the cache directory {$root}.");
        }
        $this->root = $real;
    }

    /** Payload directory of a valid entry, or null (an invalid entry is removed). */
    public function get(CacheKey $key): ?string
    {
        $entry = $this->entryDir($key);
        if (!is_dir($entry)) {
            return null;
        }
        if (!self::valid($entry, $key)) {
            $this->discard($entry);

            return null;
        }
        @touch($entry . '/' . self::ENTRY_FILE);

        return $entry . '/' . self::PAYLOAD_DIR;
    }

    /**
     * Returns the entry for $key, producing it on a miss. $produce receives a
     * new, empty directory to fill; if it throws, nothing is cached and the
     * exception propagates.
     *
     * @param \Closure(string): void $produce
     */
    public function remember(CacheKey $key, \Closure $produce): CacheEntry
    {
        $hit = $this->get($key);
        if ($hit !== null) {
            return new CacheEntry($key, $hit, true);
        }
        $lock = $this->lock($key);
        try {
            $hit = $this->get($key);
            if ($hit !== null) {
                return new CacheEntry($key, $hit, true);
            }
            $staging = $this->root . '/staging/' . $key->digest . '-' . bin2hex(random_bytes(6));
            $payload = $staging . '/' . self::PAYLOAD_DIR;
            if (!ErrorCapture::run(static fn (): bool => mkdir($payload, 0o755, true))) {
                throw new StorageException('Cannot create a cache staging directory.');
            }
            try {
                $produce($payload);
                $this->seal($staging, $key);
                $entry = $this->entryDir($key);
                if (file_exists($entry)) {
                    $this->discard($entry);
                }
                $parent = dirname($entry);
                if (!is_dir($parent) && !ErrorCapture::run(static fn (): bool => mkdir($parent, 0o755, true)) && !is_dir($parent)) {
                    throw new StorageException('Cannot create the cache entry directory.');
                }
                if (!ErrorCapture::run(static fn (): bool => rename($staging, $entry))) {
                    throw new StorageException("Cannot move the cache entry {$key->digest} into place.");
                }
            } finally {
                Tree::remove($staging);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($this->maxBytes > 0) {
            $this->prune($this->maxBytes, null, $key);
        }

        return new CacheEntry($key, $this->entryDir($key) . '/' . self::PAYLOAD_DIR, false);
    }

    /** Removes the entry for $key; true when there was one. */
    public function invalidate(CacheKey $key): bool
    {
        $entry = $this->entryDir($key);
        if (!is_dir($entry)) {
            return false;
        }
        $this->discard($entry);

        return true;
    }

    /**
     * Removes entries older than $maxAgeSeconds (by last use), then the least
     * recently used ones until the total fits $maxBytes, and staging left by
     * crashed builds. $keep is never removed.
     *
     * @return array{removed: int, bytes: int} entries removed and bytes kept
     */
    public function prune(?int $maxBytes = null, ?int $maxAgeSeconds = null, ?CacheKey $keep = null): array
    {
        $now = time();
        foreach (self::glob($this->root . '/staging/*') as $staging) {
            if ($now - (int) filemtime($staging) > self::STALE_STAGING_SECONDS) {
                Tree::remove($staging);
            }
        }
        $entries = [];
        foreach (self::glob($this->root . '/entries/*/*') as $entry) {
            $used = @filemtime($entry . '/' . self::ENTRY_FILE);
            $entries[] = ['path' => $entry, 'used' => $used === false ? 0 : $used, 'bytes' => Tree::bytes($entry)];
        }
        usort($entries, static fn (array $a, array $b): int => [$a['used'], $a['path']] <=> [$b['used'], $b['path']]);
        $total = array_sum(array_column($entries, 'bytes'));
        $removed = 0;
        $keepPath = $keep === null ? null : $this->entryDir($keep);
        foreach ($entries as $entry) {
            if ($entry['path'] === $keepPath) {
                continue;
            }
            $tooOld = $maxAgeSeconds !== null && $now - $entry['used'] > $maxAgeSeconds;
            $tooBig = $maxBytes !== null && $total > $maxBytes;
            if ($tooOld || $tooBig) {
                $this->discard($entry['path']);
                $total -= $entry['bytes'];
                $removed++;
            }
        }

        return ['removed' => $removed, 'bytes' => $total];
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $found = glob($pattern, GLOB_ONLYDIR);

        return $found === false ? [] : $found;
    }

    private function entryDir(CacheKey $key): string
    {
        return $this->root . '/entries/' . substr($key->digest, 0, 2) . '/' . $key->digest;
    }

    /**
     * @return resource
     */
    private function lock(CacheKey $key)
    {
        $path = $this->root . '/locks/' . $key->digest . '.lock';
        $handle = ErrorCapture::run(static fn () => fopen($path, 'c'));
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new StorageException("Cannot lock the cache entry {$key->digest}.");
        }

        return $handle;
    }

    private function seal(string $staging, CacheKey $key): void
    {
        $payload = $staging . '/' . self::PAYLOAD_DIR;
        $other = [];
        $files = [];
        foreach (Tree::files($payload, $other) as $file) {
            $files[$file] = ['sha256' => (string) hash_file('sha256', $payload . '/' . $file), 'bytes' => (int) filesize($payload . '/' . $file)];
        }
        if ($other !== []) {
            throw new StorageException('A cached build may only contain regular files, found: ' . implode(', ', $other) . '.');
        }
        if ($files === []) {
            throw new StorageException('A cached build produced no files.');
        }
        $entry = ['format' => self::ENTRY_FORMAT, 'version' => self::ENTRY_VERSION, 'digest' => $key->digest, 'key' => $key->material, 'files' => $files];
        if (file_put_contents($staging . '/' . self::ENTRY_FILE, CanonicalJson::encode($entry) . "\n") === false) {
            throw new StorageException('Cannot write the cache entry manifest.');
        }
    }

    private static function valid(string $entry, CacheKey $key): bool
    {
        $json = @file_get_contents($entry . '/' . self::ENTRY_FILE);
        $doc = $json === false ? null : json_decode($json, true, 64);
        if (!is_array($doc) || ($doc['format'] ?? null) !== self::ENTRY_FORMAT || ($doc['version'] ?? null) !== self::ENTRY_VERSION
            || ($doc['digest'] ?? null) !== $key->digest || !is_array($doc['files'] ?? null)
            || CanonicalJson::encode($doc['key'] ?? null) !== CanonicalJson::encode($key->material)) {
            return false;
        }
        $payload = $entry . '/' . self::PAYLOAD_DIR;
        if (!is_dir($payload)) {
            return false;
        }
        $other = [];
        $present = Tree::files($payload, $other);
        $listed = array_map(strval(...), array_keys($doc['files']));
        sort($listed);
        if ($other !== [] || $present !== $listed) {
            return false;
        }
        foreach ($doc['files'] as $file => $meta) {
            if (!is_array($meta) || hash_file('sha256', $payload . '/' . $file) !== ($meta['sha256'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** Moves an entry aside before deleting it, so a concurrent reader never sees half of it. */
    private function discard(string $entry): void
    {
        $trash = $this->root . '/staging/discard-' . bin2hex(random_bytes(6));
        if (ErrorCapture::run(static fn (): bool => rename($entry, $trash))) {
            Tree::remove($trash);
        } else {
            Tree::remove($entry);
        }
    }
}
