<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\GitHub;

final readonly class Release
{
    /**
     * @param list<ReleaseAsset> $assets
     */
    public function __construct(
        public int $id,
        public string $tagName,
        public string $body,
        public array $assets,
    ) {
    }

    public function asset(string $name): ?ReleaseAsset
    {
        foreach ($this->assets as $asset) {
            if ($asset->name === $name) {
                return $asset;
            }
        }

        return null;
    }

    /**
     * SHA256SUMS is uploaded last, after every other asset was checked, so a
     * completed SHA256SUMS and no unfinished upload mark a complete release.
     */
    public function complete(): bool
    {
        foreach ($this->assets as $asset) {
            if ($asset->state !== 'uploaded') {
                return false;
            }
        }

        return $this->asset('SHA256SUMS') !== null;
    }
}
