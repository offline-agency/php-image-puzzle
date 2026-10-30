<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\ExportOptions;

/**
 * Builds the version 2 manifest (docs/schemas/manifest.schema.json).
 *
 * Piece "x/y/width/height" keep their 1.x meaning: the logical cell in the
 * source. "rendered" is the placement offset and size of the piece image and
 * "path" its outline in source coordinates. onManifestBuild may only change
 * the "extensions" object; any change to the core document is rejected so a
 * manifest always matches its schema and the files on disk.
 */
final class ManifestBuilder
{
    public const VERSION = 2;
    public const SCHEMA_ID = 'https://raw.githubusercontent.com/offline-agency/php-image-puzzle/HEAD/docs/schemas/v2/manifest.schema.json';

    /**
     * @param array{topology: string, style: string, seed: int, samples: int, version: int} $geometry
     * @return array<string, mixed>
     */
    public static function build(
        OutputPlan $plan,
        ?string $source,
        int $rows,
        int $cols,
        int $sourceWidth,
        int $sourceHeight,
        array $geometry,
        ExportOptions $options,
    ): array {
        $pieces = [];
        $shapes = $plan->shapes;
        uasort($shapes, static fn ($a, $b): int => [$a->row, $a->col] <=> [$b->row, $b->col]);
        foreach ($shapes as $id => $shape) {
            $entry = [
                'id' => $shape->id,
                'row' => $shape->row,
                'col' => $shape->col,
                'x' => $shape->logical->x,
                'y' => $shape->logical->y,
                'width' => $shape->logical->width,
                'height' => $shape->logical->height,
                'filename' => $plan->filenames[$id],
                'rendered' => $shape->rendered->toArray(),
            ];
            if ($options->getIncludePaths()) {
                $entry['path'] = $shape->svgPath();
            }
            $pieces[] = $entry;
        }

        return [
            '$schema' => self::SCHEMA_ID,
            'manifestVersion' => self::VERSION,
            'source' => $source,
            'rows' => $rows,
            'cols' => $cols,
            'sourceWidth' => $sourceWidth,
            'sourceHeight' => $sourceHeight,
            'format' => $options->getFormat(),
            'geometry' => [
                'version' => $geometry['version'],
                'topology' => $geometry['topology'],
                'style' => $geometry['style'],
                'seed' => $geometry['seed'],
                'samples' => $geometry['samples'],
                'compositing' => $geometry['samples'] === 1 ? 'exact' : 'additive',
            ],
            'pieces' => $pieces,
            'extensions' => new \stdClass(),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    public static function applyHook(array $manifest, ExportOptions $options): array
    {
        $hook = $options->getOnManifestBuild();
        if ($hook === null) {
            return $manifest;
        }
        $result = $hook($manifest);
        if (!is_array($result)) {
            throw new ExportFailedException('onManifestBuild must return an array.');
        }
        $extensions = $result['extensions'] ?? new \stdClass();
        if (!is_array($extensions) && !$extensions instanceof \stdClass) {
            throw new ExportFailedException('Manifest "extensions" must be an object.');
        }
        if (is_array($extensions) && $extensions !== [] && array_is_list($extensions)) {
            throw new ExportFailedException('Manifest "extensions" must be an object, not a list.');
        }
        $core = $manifest;
        unset($core['extensions'], $result['extensions']);
        if ($result !== $core) {
            throw new ExportFailedException('onManifestBuild may only modify the "extensions" object of the manifest.');
        }
        $core['extensions'] = $extensions === [] ? new \stdClass() : $extensions;

        return $core;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function encode(array $manifest): string
    {
        try {
            return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } catch (\JsonException $e) {
            throw new ExportFailedException('Failed to encode manifest: ' . $e->getMessage(), 0, $e);
        }
    }
}
