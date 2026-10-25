<?php

declare(strict_types=1);

namespace ImagePuzzle\Storage;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Maps internal storage keys to the URLs consumers fetch. Keys stay a server
 * concern: hosts choose a public prefix, a CDN, or their own signing.
 *
 * The player resolves a definition's asset paths relative to the definition
 * URL, so a resolver must keep one generation's objects under one URL
 * prefix. Per-object signed URLs (expiring query strings) do not compose
 * that way: serve them through a host route that signs each requested key,
 * and resolve to that route here.
 */
abstract class UrlResolver
{
    abstract public function url(string $key): string;

    /** Public objects under one base URL (a bucket website, CDN or static route). */
    public static function prefix(string $baseUrl): self
    {
        if (preg_match('~^https?://[^\s]+$|^/[^\s]*$~', $baseUrl) !== 1) {
            throw new InvalidArgumentException('The base URL must be an http(s) URL or an absolute path.');
        }
        $base = rtrim($baseUrl, '/');

        return new class ($base) extends UrlResolver {
            public function __construct(private readonly string $base)
            {
            }

            public function url(string $key): string
            {
                return $this->base . '/' . implode('/', array_map(rawurlencode(...), explode('/', StorageKey::assert($key))));
            }
        };
    }

    /**
     * A host-supplied mapping, for example a route that checks access and
     * redirects to an expiring object-store URL.
     *
     * @param \Closure(string): string $map receives a checked key
     */
    public static function using(\Closure $map): self
    {
        return new class ($map) extends UrlResolver {
            /** @param \Closure(string): string $map */
            public function __construct(private readonly \Closure $map)
            {
            }

            public function url(string $key): string
            {
                return ($this->map)(StorageKey::assert($key));
            }
        };
    }
}
