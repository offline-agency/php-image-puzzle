<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\GitHub;

final readonly class ChangedFile
{
    /**
     * @param string $status added, modified, removed, renamed, copied or changed
     * @param string $patch  unified diff hunk, empty when the API omits it
     */
    public function __construct(
        public string $path,
        public string $status,
        public string $patch = '',
    ) {
    }
}
