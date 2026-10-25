<?php

declare(strict_types=1);

namespace ImagePuzzle\Storage;

/**
 * A published puzzle: storage keys only. Turn keys into consumer URLs with
 * a UrlResolver; never expose the keys of other generations or names.
 */
final readonly class Publication
{
    /**
     * @param list<string> $files paths relative to the generation
     */
    public function __construct(
        public string $name,
        public string $referenceKey,
        public string $generation,
        public string $definitionKey,
        public string $definitionId,
        public array $files,
        /** The generation already existed with identical content. */
        public bool $reused,
    ) {
    }

    /** URL the player loads; the definition's relative asset paths resolve next to it. */
    public function definitionUrl(UrlResolver $urls): string
    {
        return $urls->url($this->definitionKey);
    }
}
