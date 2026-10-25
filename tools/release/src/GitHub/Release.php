<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\GitHub;

final readonly class Release
{
    /**
     * @param list<string> $assets names of the uploaded assets
     */
    public function __construct(
        public int $id,
        public string $tagName,
        public string $body,
        public array $assets,
    ) {
    }
}
