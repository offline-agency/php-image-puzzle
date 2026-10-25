<?php

declare(strict_types=1);

namespace ImagePuzzle\Storage;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\StorageException;
use ImagePuzzle\Game\CanonicalJson;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\PuzzleDirectory;
use ImagePuzzle\Support\ErrorCapture;

/**
 * Publishes a built puzzle to an OutputTarget so that readers only ever see
 * complete puzzles, on backends without rename or multi-object transactions:
 *
 * 1. Every file goes under an immutable generation key,
 *    `generations/<definition id>-<content digest>/`, named after a digest
 *    of all file contents, so a generation key never holds different bytes.
 * 2. generation.json lists every file with its SHA-256 and size.
 * 3. Every object is checked (definition and generation manifest read back,
 *    the others checked for existence).
 * 4. Only then is the reference `puzzles/<name>.json` written. Readers
 *    resolve a name through its reference, never by listing generations.
 *
 * A failure before step 4 leaves the previous reference untouched;
 * unreferenced generations are garbage, safe to delete by key prefix (for
 * example with an object-store lifecycle rule). Republishing identical
 * content reuses the existing generation when its manifest matches, and
 * otherwise rewrites the same bytes (repairing an interrupted upload).
 */
final class Publisher
{
    public const REFERENCE_FORMAT = 'image-puzzle/publication';
    public const REFERENCE_VERSION = 1;
    public const GENERATION_FILE = 'generation.json';
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    public function __construct(
        private readonly OutputTarget $target,
        private readonly string $prefix = '',
    ) {
        if ($prefix !== '') {
            StorageKey::assert($prefix);
        }
    }

    public function publish(PuzzleDirectory $puzzle, string $name): Publication
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException("Publication name \"{$name}\" must be 1-64 lowercase letters, digits, \"_\" or \"-\".");
        }
        $files = [];
        foreach ([...$puzzle->referencedFiles(), DefinitionBuilder::DEFINITION_FILE] as $relative) {
            $path = $relative === DefinitionBuilder::DEFINITION_FILE ? $puzzle->path . '/' . $relative : $puzzle->file($relative);
            $hash = hash_file('sha256', $path);
            $size = filesize($path);
            if ($hash === false || $size === false) {
                throw new StorageException("Cannot read {$relative} from the puzzle directory.");
            }
            $files[StorageKey::assert($relative)] = ['sha256' => $hash, 'bytes' => $size];
        }
        ksort($files);
        $digest = hash('sha256', CanonicalJson::encode($files));
        $generation = StorageKey::join($this->prefix, 'generations/' . $puzzle->definition->id . '-' . substr($digest, 0, 16));
        $manifest = CanonicalJson::encode([
            'format' => 'image-puzzle/generation',
            'version' => 1,
            'definitionId' => $puzzle->definition->id,
            'digest' => $digest,
            'files' => $files,
        ]) . "\n";
        $manifestKey = $generation . '/' . self::GENERATION_FILE;

        $reused = $this->target->exists($manifestKey) && $this->target->read($manifestKey) === $manifest;
        if (!$reused) {
            foreach ($files as $relative => $_) {
                $source = $relative === DefinitionBuilder::DEFINITION_FILE ? $puzzle->path . '/' . $relative : $puzzle->file($relative);
                $stream = ErrorCapture::run(static fn () => fopen($source, 'rb'));
                if ($stream === false) {
                    throw new StorageException("Cannot read {$relative} from the puzzle directory.");
                }
                try {
                    $this->target->writeStream($generation . '/' . $relative, $stream);
                } finally {
                    fclose($stream);
                }
            }
            $this->target->write($manifestKey, $manifest);
        }
        $this->verify($generation, $files, $manifestKey, $manifest);

        $reference = [
            'format' => self::REFERENCE_FORMAT,
            'version' => self::REFERENCE_VERSION,
            'name' => $name,
            'definitionId' => $puzzle->definition->id,
            'generation' => $generation,
            'definition' => $generation . '/' . DefinitionBuilder::DEFINITION_FILE,
        ];
        $referenceKey = StorageKey::join($this->prefix, "puzzles/{$name}.json");
        $this->target->write($referenceKey, CanonicalJson::encode($reference) . "\n");

        return new Publication($name, $referenceKey, $generation, $reference['definition'], $puzzle->definition->id, array_keys($files), $reused);
    }

    /** Reads a published name back through its reference. */
    public function resolve(string $name): Publication
    {
        $referenceKey = StorageKey::join($this->prefix, "puzzles/{$name}.json");
        $reference = json_decode($this->target->read($referenceKey), true, 8);
        if (!is_array($reference) || ($reference['format'] ?? null) !== self::REFERENCE_FORMAT || ($reference['version'] ?? null) !== self::REFERENCE_VERSION
            || !is_string($reference['generation'] ?? null) || !is_string($reference['definitionId'] ?? null)) {
            throw new StorageException("Reference {$referenceKey} is not a version " . self::REFERENCE_VERSION . ' publication reference.');
        }
        $generation = StorageKey::assert($reference['generation']);
        $manifest = json_decode($this->target->read($generation . '/' . self::GENERATION_FILE), true, 8);
        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            throw new StorageException("Generation {$generation} has no readable manifest.");
        }

        return new Publication($name, $referenceKey, $generation, $generation . '/' . DefinitionBuilder::DEFINITION_FILE, $reference['definitionId'], array_map(strval(...), array_keys($manifest['files'])), true);
    }

    /**
     * @param array<string, array{sha256: string, bytes: int}> $files
     */
    private function verify(string $generation, array $files, string $manifestKey, string $manifest): void
    {
        if ($this->target->read($manifestKey) !== $manifest) {
            throw new StorageException("Generation {$generation} holds a different manifest; refusing to publish over it.");
        }
        foreach ($files as $relative => $file) {
            $key = $generation . '/' . $relative;
            if ($relative === DefinitionBuilder::DEFINITION_FILE) {
                if (hash('sha256', $this->target->read($key)) !== $file['sha256']) {
                    throw new StorageException("{$key} does not match the published definition.");
                }
            } elseif (!$this->target->exists($key)) {
                throw new StorageException("{$key} is missing after upload.");
            }
        }
    }
}
