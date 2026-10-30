<?php

declare(strict_types=1);

namespace ImagePuzzle\Livewire;

use ImagePuzzle\Game\GameDefinition;

/**
 * A resolved puzzle: its reference key, validated definition, the local
 * directory its relative asset paths are read from and, for puzzles stored on
 * a host record, that record. The default gate requires the "view" ability
 * on the subject, so record policies (and their tenant checks) apply.
 */
final readonly class StoredPuzzle
{
    public function __construct(
        public string $key,
        public GameDefinition $definition,
        public string $directory,
        public ?object $subject = null,
    ) {
    }

    /**
     * Asset paths the definition references; the asset route serves nothing else.
     *
     * @return list<string>
     */
    public function assetPaths(): array
    {
        $paths = [$this->definition->image];
        foreach ($this->definition->tiles as $tile) {
            $image = $tile->attributes['image'] ?? null;
            if (is_string($image)) {
                $paths[] = $image;
            }
        }

        return array_values(array_unique(array_filter($paths, static fn (string $path): bool => !str_contains($path, '://'))));
    }
}
