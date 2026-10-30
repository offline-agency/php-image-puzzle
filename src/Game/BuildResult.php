<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

/**
 * Outcome of DefinitionBuilder::build(): the definition and the files written.
 */
final readonly class BuildResult
{
    /**
     * @param list<string> $files paths relative to $directory (definition.json included)
     */
    public function __construct(
        public GameDefinition $definition,
        public string $directory,
        public array $files,
    ) {
    }

    public function definitionPath(): string
    {
        return $this->directory . '/' . DefinitionBuilder::DEFINITION_FILE;
    }
}
