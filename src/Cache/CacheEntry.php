<?php

declare(strict_types=1);

namespace ImagePuzzle\Cache;

final readonly class CacheEntry
{
    public function __construct(
        public CacheKey $key,
        /** Directory holding the cached files; shared, do not modify. */
        public string $path,
        /** Served from the cache rather than produced by this call. */
        public bool $hit,
    ) {
    }
}
