<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\ExportResult;
use ImagePuzzle\Geometry\PieceShape;

/**
 * One export run, shared by the sequential and parallel exporters.
 *
 * Publication semantics (docs/EXPORT.md):
 *
 * - An exclusive lock (a file in sys_get_temp_dir() derived from the resolved
 *   output path) serialises concurrent exports to the same directory on one host.
 * - Pieces are encoded into a private staging directory inside the output
 *   directory, created with mode 0700 and exclusive file creation, then
 *   verified (non-empty, decodable, expected format and dimensions).
 * - in_place mode: once every piece is staged, the previous manifest is removed,
 *   each piece is moved into place with an atomic rename (per-file atomic
 *   replacement, never a symlink target), and the new manifest is published
 *   last with an atomic rename. A failure before publication leaves the
 *   previous export untouched; a failure during publication leaves no manifest,
 *   which marks the directory as incomplete until the export is re-run.
 * - generation mode: files are written to generations/<id>/ and "current.json"
 *   is atomically replaced to point to the new generation, giving readers an
 *   all-or-nothing view.
 * - overwrite=false keeps existing piece files, but only after checking that
 *   each is a regular file with the expected format and rendered dimensions;
 *   mismatching files abort the export before anything is written. The
 *   manifest is always rewritten.
 */
final class ExportSession
{
    private const LOCK_FILE = '.image-puzzle.lock';

    /** @var resource|null */
    private $lock;
    private ?string $staging = null;
    private string $outputDirectory;
    private ?string $generationId = null;

    /** @var array<string, true> piece ids whose existing file is reused */
    private array $reused = [];

    /**
     * @param string $root resolved (real) output directory used for all file operations
     * @param string $displayRoot the directory as given by the caller, used in results and hook paths
     */
    private function __construct(
        private readonly string $root,
        private readonly string $displayRoot,
        private readonly OutputPlan $plan,
        private readonly ExportOptions $options,
    ) {
        $this->outputDirectory = $root;
    }

    public static function open(string $directory, ?string $baseDirectory, OutputPlan $plan, ExportOptions $options): self
    {
        ImageEncoder::assertSupported($options->getFormat());
        $root = PathGuard::prepare($directory, $baseDirectory);
        $session = new self($root, rtrim($directory, '/\\'), $plan, $options);
        $session->acquireLock();
        try {
            if ($options->getPublishMode() === ExportOptions::PUBLISH_GENERATION) {
                $session->generationId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
                $generations = $root . DIRECTORY_SEPARATOR . 'generations';
                if (!is_dir($generations) && !@mkdir($generations, 0755) && !is_dir($generations)) {
                    throw new ExportFailedException("Failed to create directory: {$generations}");
                }
                if (is_link($generations)) {
                    throw new ExportFailedException("Generations directory is a symbolic link: {$generations}");
                }
                $session->outputDirectory = $generations . DIRECTORY_SEPARATOR . $session->generationId;
            } else {
                $session->checkExisting();
            }
            $session->staging = $root . DIRECTORY_SEPARATOR . OutputPlan::RESERVED_PREFIX . '-staging-' . bin2hex(random_bytes(8));
            if (!@mkdir($session->staging, 0700)) {
                throw new ExportFailedException("Failed to create staging directory in {$root}");
            }
        } catch (\Throwable $e) {
            $session->abort();
            throw $e;
        }

        return $session;
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Final destination path of a piece (used for hooks and results).
     */
    public function destination(string $pieceId): string
    {
        return $this->display($this->outputDirectory) . DIRECTORY_SEPARATOR . $this->plan->filenames[$pieceId];
    }

    /**
     * Map a resolved path below the root back to the caller's spelling.
     */
    private function display(string $path): string
    {
        return $this->displayRoot . substr($path, strlen($this->root));
    }

    public function stagingPath(string $pieceId): string
    {
        if ($this->staging === null) {
            throw new ExportFailedException('Export session is closed.');
        }

        return $this->staging . DIRECTORY_SEPARATOR . $this->plan->filenames[$pieceId];
    }

    public function stagingDirectory(): string
    {
        if ($this->staging === null) {
            throw new ExportFailedException('Export session is closed.');
        }

        return $this->staging;
    }

    /**
     * Piece ids that must be rendered (not reused under overwrite=false).
     *
     * @return list<string>
     */
    public function pendingIds(): array
    {
        return array_values(array_filter(array_keys($this->plan->filenames), fn (string $id): bool => !isset($this->reused[$id])));
    }

    public function verifyStaged(string $pieceId): void
    {
        $shape = $this->plan->shapes[$pieceId];
        ImageEncoder::verify($this->stagingPath($pieceId), $this->options->getFormat(), $shape->rendered->width, $shape->rendered->height);
    }

    /**
     * Publish all staged pieces and the manifest.
     *
     * @param array<string, mixed> $manifest
     * @param callable(string): void $afterPublish invoked with each published piece id
     */
    public function publish(array $manifest, callable $afterPublish): ExportResult
    {
        $pending = $this->pendingIds();
        foreach ($pending as $id) {
            $this->verifyStaged($id);
        }
        $json = ManifestBuilder::encode($manifest);
        // Results expose the manifest exactly as a JSON reader will see it.
        $manifest = self::decodeObject($json);
        $manifestStaging = $this->stagingDirectory() . DIRECTORY_SEPARATOR . OutputPlan::RESERVED_PREFIX . '-manifest.json';
        if (file_put_contents($manifestStaging, $json, LOCK_EX) !== strlen($json)) {
            throw new ExportFailedException("Failed to write manifest: {$manifestStaging}");
        }

        if ($this->generationId !== null) {
            return $this->publishGeneration($pending, $manifestStaging, $manifest, $afterPublish);
        }

        $manifestPath = $this->root . DIRECTORY_SEPARATOR . $this->plan->manifestFilename;
        if ((file_exists($manifestPath) || is_link($manifestPath)) && !@unlink($manifestPath)) {
            throw new ExportFailedException("Failed to remove previous manifest: {$manifestPath}");
        }
        foreach ($pending as $id) {
            $destination = $this->outputDirectory . DIRECTORY_SEPARATOR . $this->plan->filenames[$id];
            if (is_dir($destination) && !is_link($destination)) {
                throw new ExportFailedException("Destination is a directory: {$destination}");
            }
            if (!@rename($this->stagingPath($id), $this->outputDirectory . DIRECTORY_SEPARATOR . $this->plan->filenames[$id])) {
                throw new ExportFailedException("Failed to publish piece file: {$destination}");
            }
            $afterPublish($id);
        }
        if (!@rename($manifestStaging, $manifestPath)) {
            throw new ExportFailedException("Failed to publish manifest: {$manifestPath}");
        }

        return $this->result($this->display($manifestPath), $manifest);
    }

    /**
     * Remove staging data and release the lock. Safe to call repeatedly.
     */
    public function abort(): void
    {
        if ($this->staging !== null && is_dir($this->staging)) {
            self::removeTree($this->staging);
        }
        $this->staging = null;
        if ($this->generationId !== null && is_dir($this->outputDirectory)) {
            $pointer = $this->currentPointer();
            if ($pointer !== $this->generationId) {
                self::removeTree($this->outputDirectory);
            }
        }
        $this->releaseLock();
    }

    public function close(): void
    {
        $this->generationId = null;
        $this->abort();
    }

    /**
     * Resolve the published generation directory of a generation-mode export.
     */
    public static function currentGenerationDirectory(string $root): ?string
    {
        $pointer = $root . DIRECTORY_SEPARATOR . 'current.json';
        if (!is_file($pointer)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($pointer), true);
        if (!is_array($data) || !is_string($data['generation'] ?? null) || preg_match('/^[0-9TZ]+-[0-9a-f]{8}$/', $data['generation']) !== 1) {
            throw new ExportFailedException("Invalid generation pointer: {$pointer}");
        }

        return $root . DIRECTORY_SEPARATOR . 'generations' . DIRECTORY_SEPARATOR . $data['generation'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new ExportFailedException('Encoded manifest is not a JSON object.');
        }
        $object = [];
        foreach ($decoded as $key => $value) {
            $object[(string) $key] = $value;
        }

        return $object;
    }

    /**
     * Delete all but the $keep most recent generations (never the current one).
     *
     * @return list<string> removed generation ids
     */
    public static function pruneGenerations(string $root, int $keep = 1): array
    {
        $dir = $root . DIRECTORY_SEPARATOR . 'generations';
        if (!is_dir($dir) || is_link($dir)) {
            return [];
        }
        $current = self::currentGenerationDirectory($root);
        $entries = scandir($dir);
        $ids = array_values(array_filter($entries === false ? [] : $entries, static fn (string $e): bool => preg_match('/^[0-9TZ]+-[0-9a-f]{8}$/', $e) === 1));
        rsort($ids);
        $removed = [];
        foreach (array_slice($ids, max(0, $keep)) as $id) {
            $path = $dir . DIRECTORY_SEPARATOR . $id;
            if ($path === $current) {
                continue;
            }
            self::removeTree($path);
            $removed[] = $id;
        }

        return $removed;
    }

    /**
     * @param list<string> $pending
     * @param array<string, mixed> $manifest
     * @param callable(string): void $afterPublish
     */
    private function publishGeneration(array $pending, string $manifestStaging, array $manifest, callable $afterPublish): ExportResult
    {
        $staging = $this->stagingDirectory();
        $manifestTarget = $staging . DIRECTORY_SEPARATOR . $this->plan->manifestFilename;
        if (!@rename($manifestStaging, $manifestTarget)) {
            throw new ExportFailedException('Failed to stage manifest.');
        }
        // The staging directory becomes the generation directory in one rename.
        if (!@rename($staging, $this->outputDirectory)) {
            throw new ExportFailedException("Failed to publish generation directory: {$this->outputDirectory}");
        }
        $this->staging = null;
        @chmod($this->outputDirectory, 0755);
        $pointer = ['generation' => $this->generationId, 'manifest' => 'generations/' . $this->generationId . '/' . $this->plan->manifestFilename];
        $pointerJson = json_encode($pointer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $tmp = $this->root . DIRECTORY_SEPARATOR . OutputPlan::RESERVED_PREFIX . '-current-' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $pointerJson) !== strlen($pointerJson) || !@rename($tmp, $this->root . DIRECTORY_SEPARATOR . 'current.json')) {
            @unlink($tmp);
            throw new ExportFailedException('Failed to publish current.json');
        }
        foreach ($pending as $id) {
            $afterPublish($id);
        }

        return $this->result($this->display($this->outputDirectory) . DIRECTORY_SEPARATOR . $this->plan->manifestFilename, $manifest);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function result(string $manifestPath, array $manifest): ExportResult
    {
        $files = [];
        foreach (array_keys($this->plan->filenames) as $id) {
            $files[] = $this->destination($id);
        }
        $files[] = $manifestPath;
        $reused = array_map(fn (string $id): string => $this->destination($id), array_keys($this->reused));

        return new ExportResult($this->display($this->outputDirectory), $files, $manifestPath, $manifest, $reused, $this->generationId);
    }

    private function checkExisting(): void
    {
        $mismatches = [];
        foreach ($this->plan->filenames as $id => $filename) {
            $path = $this->root . DIRECTORY_SEPARATOR . $filename;
            if (!file_exists($path) && !is_link($path)) {
                continue;
            }
            if (is_link($path)) {
                $mismatches[] = "{$filename} is a symbolic link";
                continue;
            }
            if (is_dir($path)) {
                $mismatches[] = "{$filename} is a directory";
                continue;
            }
            if ($this->options->getOverwrite()) {
                continue;
            }
            $shape = $this->plan->shapes[$id];
            try {
                ImageEncoder::verify($path, $this->options->getFormat(), $shape->rendered->width, $shape->rendered->height);
                $this->reused[$id] = true;
            } catch (ExportFailedException $e) {
                $mismatches[] = $e->getMessage();
            }
        }
        $manifestPath = $this->root . DIRECTORY_SEPARATOR . $this->plan->manifestFilename;
        if (is_link($manifestPath) || is_dir($manifestPath)) {
            $mismatches[] = "{$this->plan->manifestFilename} is not a regular file";
        }
        if ($mismatches !== []) {
            throw new ExportFailedException("Existing output cannot be used (no files were written):\n- " . implode("\n- ", $mismatches));
        }
    }

    private function currentPointer(): ?string
    {
        $current = self::currentGenerationDirectory($this->root);

        return $current === null ? null : basename($current);
    }

    private function acquireLock(): void
    {
        // Kept outside the output directory so exports leave no extra file there.
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . self::LOCK_FILE . '-' . hash('sha256', $this->root);
        if (is_link($path)) {
            throw new ExportFailedException("Lock file is a symbolic link: {$path}");
        }
        $handle = @fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new ExportFailedException("Failed to lock output directory: {$this->root}");
        }
        $this->lock = $handle;
    }

    private function releaseLock(): void
    {
        if ($this->lock !== null) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
            $this->lock = null;
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }

    /**
     * @return array<string, PieceShape>
     */
    public function shapes(): array
    {
        return $this->plan->shapes;
    }
}
