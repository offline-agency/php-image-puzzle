<?php

declare(strict_types=1);

namespace ImagePuzzle\Cache;

use ImagePuzzle\Config\PuzzleConfig;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Export\ManifestBuilder;
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\CanonicalJson;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\Presets;
use ImagePuzzle\GeometryOptions;

/**
 * Identity of a generated puzzle: everything that can change its files.
 *
 * - the source image bytes (SHA-256);
 * - the normalised build configuration (kind, preset, custom parameters,
 *   seed and crop);
 * - output options (format, quality, resolutions, atlas);
 * - the definition, preset, geometry, manifest and configuration versions,
 *   plus GENERATOR_REVISION for output changes that keep every format;
 * - the backend: PHP and GD versions, whose encoders produce the bytes.
 *
 * Two keys with the same digest describe byte-identical outputs as far as
 * this library can tell; anything else is a different entry.
 */
final readonly class CacheKey
{
    /** Bump when generated files change without a format or version change. */
    public const GENERATOR_REVISION = 1;

    /**
     * @param array<string, mixed> $material
     */
    private function __construct(
        public string $digest,
        public array $material,
    ) {
    }

    public static function forBuild(string $sourceSha256, BuildConfiguration $config, AssetOptions $assets = new AssetOptions()): self
    {
        if (preg_match('/^[0-9a-f]{64}$/', $sourceSha256) !== 1) {
            throw new InvalidArgumentException('The source digest must be a lowercase SHA-256 hex string.');
        }
        $material = [
            'format' => 'image-puzzle/cache-key',
            'version' => 1,
            'source' => $sourceSha256,
            'config' => $config->toArray(),
            'assets' => [
                'format' => $assets->format,
                'quality' => $assets->quality,
                'resolutions' => $assets->resolutions,
                'atlas' => $assets->atlas,
                'atlasPadding' => $assets->atlasPadding,
                'atlasMaxSize' => $assets->atlasMaxSize,
            ],
            ...self::engine(),
        ];

        return new self(CanonicalJson::digest($material), $material);
    }

    /**
     * Library format versions and the image backend: what makes the same
     * inputs produce different files.
     *
     * @return array{versions: array<string, int>, backend: array{php: string, gd: string}}
     */
    public static function engine(): array
    {
        $gd = function_exists('gd_info') ? gd_info()['GD Version'] ?? 'unknown' : 'none';

        return [
            'versions' => [
                'definition' => GameDefinition::VERSION,
                'presets' => Presets::VERSION,
                'geometry' => GeometryOptions::VERSION,
                'manifest' => ManifestBuilder::VERSION,
                'puzzleConfig' => PuzzleConfig::VERSION,
                'buildConfig' => BuildConfiguration::VERSION,
                'generator' => self::GENERATOR_REVISION,
            ],
            'backend' => ['php' => PHP_VERSION, 'gd' => is_string($gd) ? $gd : 'unknown'],
        ];
    }
}
