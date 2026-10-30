<?php

declare(strict_types=1);

namespace ImagePuzzle\Batch;

use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\Doc;

/**
 * A versioned batch file (image-puzzle/batch v1), as read by
 * `image-puzzle batch`:
 *
 *     {
 *       "format": "image-puzzle/batch", "version": 1,
 *       "items": [
 *         {"id": "cat", "source": "photos/cat.jpg", "output": "out/cat",
 *          "build": {"format": "image-puzzle/build-config", "version": 1, "kind": "jigsaw", "preset": "easy", "seed": 7},
 *          "assets": {"format": "webp", "quality": 80}}
 *       ]
 *     }
 *
 * Relative source and output paths are resolved against the directory of
 * the batch file, so a batch file can be moved together with its images.
 */
final class BatchManifest
{
    public const FORMAT = 'image-puzzle/batch';
    public const VERSION = 1;
    public const MAX_JSON_BYTES = 4 * 1024 * 1024;
    public const MAX_ITEMS = 10000;

    /**
     * @param list<BatchItem> $items
     */
    public function __construct(public readonly array $items)
    {
    }

    public static function fromJson(string $json, string $baseDir): self
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidDefinitionException('The batch file is larger than ' . self::MAX_JSON_BYTES . ' bytes.');
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidDefinitionException('The batch file is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        return self::fromArray($data, $baseDir);
    }

    public static function fromArray(mixed $data, string $baseDir): self
    {
        $doc = Doc::object($data, 'batch');
        Doc::keys($doc, ['$schema', 'format', 'version', 'items'], 'batch');
        if (($doc['format'] ?? null) !== self::FORMAT) {
            throw new InvalidDefinitionException('batch.format must be "' . self::FORMAT . '".');
        }
        $version = Doc::int($doc, 'version', 'batch', 1);
        if ($version !== self::VERSION) {
            throw new InvalidDefinitionException("Unsupported batch version {$version}; this library reads version " . self::VERSION . '.');
        }
        $items = [];
        foreach (Doc::list($doc['items'] ?? null, 'batch.items', self::MAX_ITEMS) as $index => $value) {
            $path = "batch.items[{$index}]";
            $item = Doc::object($value, $path);
            Doc::keys($item, ['id', 'source', 'output', 'build', 'assets'], $path);
            $items[] = new BatchItem(
                Doc::string($item, 'id', $path, 64),
                self::resolve(Doc::string($item, 'source', $path, 4096), $baseDir),
                self::resolve(Doc::string($item, 'output', $path, 4096), $baseDir),
                BuildConfiguration::fromArray($item['build'] ?? null),
                isset($item['assets']) ? self::assets($item['assets'], "{$path}.assets") : new AssetOptions(),
            );
        }
        if ($items === []) {
            throw new InvalidDefinitionException('batch.items must list at least one item.');
        }

        return new self($items);
    }

    private static function assets(mixed $value, string $path): AssetOptions
    {
        $assets = Doc::object($value, $path);
        Doc::keys($assets, ['format', 'quality', 'resolutions', 'atlas'], $path);
        // Either a list of names ("mobile") or an object of name => width.
        $resolutions = [];
        $value = $assets['resolutions'] ?? [];
        if (is_array($value) && array_is_list($value)) {
            foreach (Doc::list($value, "{$path}.resolutions", 8) as $index => $name) {
                $resolutions[] = Doc::stringValue($name, "{$path}.resolutions[{$index}]", 16);
            }
        } else {
            foreach (Doc::object($value, "{$path}.resolutions") as $name => $width) {
                $resolutions[$name] = Doc::intValue($width, "{$path}.resolutions.{$name}");
            }
        }

        return new AssetOptions(
            isset($assets['format']) ? Doc::string($assets, 'format', $path, 8) : null,
            isset($assets['quality']) ? Doc::int($assets, 'quality', $path, 0, 100) : null,
            $resolutions,
            isset($assets['atlas']) ? Doc::string($assets, 'atlas', $path, 16) : null,
        );
    }

    private static function resolve(string $path, string $baseDir): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidDefinitionException('Batch paths must be non-empty.');
        }

        return str_starts_with($path, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1 ? $path : rtrim($baseDir, '/') . '/' . $path;
    }
}
