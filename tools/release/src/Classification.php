<?php

declare(strict_types=1);

namespace ImagePuzzle\Release;

final readonly class Classification
{
    public function __construct(
        public Bump $bump,
        public bool $explicitMajor,
        public string $reason,
    ) {
    }
}
