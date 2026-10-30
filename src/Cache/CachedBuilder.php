<?php

declare(strict_types=1);

namespace ImagePuzzle\Cache;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\PuzzleDirectory;

/**
 * DefinitionBuilder behind an AssetCache: the same source bytes, build
 * configuration, output options and library/backend versions build once.
 */
final class CachedBuilder
{
    public function __construct(
        private readonly AssetCache $cache,
        private readonly DefinitionBuilder $builder = new DefinitionBuilder(),
    ) {
    }

    public function key(string $sourcePath, BuildConfiguration $config, AssetOptions $assets = new AssetOptions()): CacheKey
    {
        $sha = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : false;
        if ($sha === false) {
            throw new InvalidArgumentException("Cannot read the source image {$sourcePath}.");
        }

        return CacheKey::forBuild($sha, $config, $assets);
    }

    /** The cached puzzle (shared and immutable: copy or publish it, do not modify it). */
    public function build(string $sourcePath, BuildConfiguration $config, AssetOptions $assets = new AssetOptions()): CachedPuzzle
    {
        $entry = $this->cache->remember(
            $this->key($sourcePath, $config, $assets),
            function (string $dir) use ($sourcePath, $config, $assets): void {
                $this->builder->buildConfigured($sourcePath, $dir, $config, $assets);
            },
        );

        return new CachedPuzzle(PuzzleDirectory::open($entry->path), $entry->hit);
    }
}
