<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\GitHub;

/**
 * One release asset. GitHub keeps an interrupted upload as an asset in the
 * "starter" state, so only "uploaded" assets count; the digest
 * ("sha256:<hex>") is reported for assets uploaded since mid-2025.
 */
final readonly class ReleaseAsset
{
    public function __construct(
        public int $id,
        public string $name,
        public string $state,
        public int $size,
        public ?string $digest = null,
    ) {
    }

    /** Whether this is a completed upload of exactly the given content. */
    public function matches(int $size, string $sha256): bool
    {
        return $this->state === 'uploaded' && $this->size === $size && ($this->digest === null || $this->digest === "sha256:{$sha256}");
    }
}
