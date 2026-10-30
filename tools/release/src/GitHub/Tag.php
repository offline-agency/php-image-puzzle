<?php

declare(strict_types=1);

namespace ImagePuzzle\Release\GitHub;

/** A tag and the commit it resolves to; the message is null for lightweight tags. */
final readonly class Tag
{
    public function __construct(
        public string $name,
        public string $commit,
        public ?string $message,
    ) {
    }
}
