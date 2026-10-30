<?php

declare(strict_types=1);

namespace ImagePuzzle\Batch;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\BuildConfiguration;

/** One puzzle to build: a source image, a new or empty output directory and its configuration. */
final readonly class BatchItem
{
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    public function __construct(
        public string $id,
        public string $source,
        public string $output,
        public BuildConfiguration $config,
        public AssetOptions $assets = new AssetOptions(),
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException("Batch item ID \"{$id}\" must be 1-64 letters, digits, \".\", \"_\" or \"-\".");
        }
    }

    /**
     * What a checkpoint binds to: changing any of it invalidates the item's
     * recorded result.
     *
     * @return array<string, mixed>
     */
    public function identity(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'output' => $this->output,
            'config' => $this->config->toArray(),
            'assets' => [
                'format' => $this->assets->format,
                'quality' => $this->assets->quality,
                'resolutions' => $this->assets->resolutions,
                'atlas' => $this->assets->atlas,
                'atlasPadding' => $this->assets->atlasPadding,
                'atlasMaxSize' => $this->assets->atlasMaxSize,
            ],
        ];
    }
}
