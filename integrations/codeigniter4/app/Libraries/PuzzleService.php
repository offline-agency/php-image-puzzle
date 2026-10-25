<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Exceptions\PageNotFoundException;
use Config\ImagePuzzle;
use ImagePuzzle\Game\BuildConfiguration;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameState;

/**
 * Builds puzzles into server-chosen directories and stores validated
 * progress. Puzzle IDs are generated here and checked against a strict
 * pattern before they are used in a path, so a request can never point
 * outside the configured directories.
 */
final class PuzzleService
{
    private const ID_PATTERN = '/^p[0-9a-f]{16}$/';

    public function __construct(
        private readonly DefinitionBuilder $builder,
        private readonly ImagePuzzle $config,
    ) {
    }

    /**
     * Builds a puzzle from an image the application already stored and
     * returns its new ID. Throws an ImagePuzzleException for images or
     * configurations the library rejects; the builder then removes the
     * directory it created, so nothing half-built is served.
     */
    public function create(string $imagePath, BuildConfiguration $config): string
    {
        $id = 'p' . bin2hex(random_bytes(8));
        $this->builder->buildConfigured($imagePath, $this->directory($id), $config);

        return $id;
    }

    public function definition(string $id): GameDefinition
    {
        $file = $this->directory($id) . '/definition.json';
        if (!is_file($file)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return GameDefinition::fromJson((string) file_get_contents($file));
    }

    /** URL path of the definition, relative to the site root. */
    public function definitionPath(string $id): string
    {
        $this->assertId($id);

        return 'puzzles/' . $id . '/definition.json';
    }

    /**
     * Validates a saved game posted by the browser and stores it.
     * Throws InvalidDefinitionException for another puzzle's state, an
     * unreachable board, corrupt history or unknown fields.
     */
    public function saveProgress(string $id, mixed $state): GameState
    {
        $saved = GameState::fromArray($this->definition($id), $state);
        $directory = $this->config->progressDirectory;
        if (!is_dir($directory)) {
            mkdir($directory, 0o750, true);
        }
        file_put_contents($directory . '/' . $id . '.json', json_encode($saved->toArray(), JSON_THROW_ON_ERROR), LOCK_EX);

        return $saved;
    }

    /**
     * @return array<string, mixed>|null the stored state document
     */
    public function progress(string $id): ?array
    {
        $this->assertId($id);
        $file = $this->config->progressDirectory . '/' . $id . '.json';
        if (!is_file($file)) {
            return null;
        }
        $state = json_decode((string) file_get_contents($file), true, 32);
        if (!is_array($state)) {
            return null;
        }
        $document = [];
        foreach ($state as $key => $value) {
            $document[(string) $key] = $value;
        }

        return $document;
    }

    private function directory(string $id): string
    {
        $this->assertId($id);

        return rtrim($this->config->publicDirectory, '/') . '/' . $id;
    }

    private function assertId(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw PageNotFoundException::forPageNotFound();
        }
    }
}
