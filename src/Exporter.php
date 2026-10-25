<?php

declare(strict_types=1);

namespace ImagePuzzle;

use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Export\ExportSession;
use ImagePuzzle\Export\ImageEncoder;
use ImagePuzzle\Export\ManifestBuilder;
use ImagePuzzle\Export\OutputPlan;
use ImagePuzzle\Export\PcntlProcessControl;
use ImagePuzzle\Export\ProcessControl;
use ImagePuzzle\Geometry\PieceShape;
use ImagePuzzle\Geometry\ShapeBuilder;

/**
 * Writes piece images and the manifest. Sequential and parallel export share
 * the same plan, path checks, overwrite rules, staging, verification,
 * publication and manifest code (see {@see ExportSession}).
 */
final class Exporter implements ExporterInterface
{
    private const ENVELOPE_VERSION = 1;

    private readonly ProcessControl $process;

    /**
     * @param ?\Closure(array<string, mixed>, int): string $workerEnvelopeFilter
     *        Internal test seam: rewrites a worker's serialized result envelope.
     */
    public function __construct(
        ?ProcessControl $process = null,
        private readonly ?\Closure $workerEnvelopeFilter = null,
    ) {
        $this->process = $process ?? new PcntlProcessControl();
    }

    public function export(Puzzle $puzzle, string $directory, ExportOptions $options, ?string $baseDirectory = null): ExportResult
    {
        $pieces = [];
        $shapes = [];
        foreach ($puzzle->getPieces() as $piece) {
            $pieces[$piece->getId()] = $piece;
            $shapes[] = $piece->getShape();
        }
        $plan = OutputPlan::create($shapes, $puzzle->getRows(), $puzzle->getCols(), $options);
        $session = ExportSession::open($directory, $baseDirectory, $plan, $options);
        try {
            $before = $options->getOnPieceBeforeSave();
            foreach ($session->pendingIds() as $id) {
                if ($before !== null) {
                    $before($pieces[$id], $session->destination($id));
                }
                ImageEncoder::write($pieces[$id]->toGdImage(), $session->stagingPath($id), $options);
            }
            $manifest = ManifestBuilder::applyHook(ManifestBuilder::build(
                $plan,
                $puzzle->getSourceBasename(),
                $puzzle->getRows(),
                $puzzle->getCols(),
                $puzzle->getWidth(),
                $puzzle->getHeight(),
                $puzzle->getGeometry(),
                $options,
            ), $options);
            $after = $options->getOnPieceAfterSave();
            $result = $session->publish($manifest, static function (string $id) use ($after, $pieces, $session): void {
                if ($after !== null) {
                    $after($pieces[$id], $session->destination($id));
                }
            });
            $session->close();

            return $result;
        } catch (\Throwable $e) {
            $session->abort();
            throw $e;
        }
    }

    /**
     * Export using forked worker processes (CLI with ext-pcntl).
     *
     * Workers each decode the source and render a contiguous chunk of pieces
     * into the staging directory; the parent validates every worker's exit
     * status and result envelope before publishing. The effective worker count
     * is bounded by ResourceLimits (worker cap, piece count and aggregate
     * memory budget). With fewer than two effective workers, without pcntl or
     * outside the CLI, the same export runs sequentially.
     *
     * onPieceBeforeSave runs inside the worker processes (their in-memory
     * side effects are not visible to the parent); onPieceAfterSave and
     * onManifestBuild run in the parent after publication.
     */
    public function exportParallel(
        string $sourcePath,
        string $directory,
        int $rows,
        int $cols,
        ExportOptions $options,
        int $workers = 4,
        ?EdgeStyleInterface $edgeStyle = null,
        ?string $baseDirectory = null,
        ?GeometryOptions $geometry = null,
        ?ResourceLimits $limits = null,
    ): ExportResult {
        if ($workers < 0) {
            throw new InvalidArgumentException('Workers must not be negative.');
        }
        $limits ??= new ResourceLimits();
        $loader = new ImageLoader($limits);
        $generator = new PuzzleGenerator($edgeStyle, $loader, $this, null, $limits, $geometry);
        if ($rows < 1 || $cols < 1) {
            throw new InvalidArgumentException($rows < 1 ? 'Rows must be at least 1.' : 'Cols must be at least 1.');
        }
        $limits->assertPieceCount($rows, $cols);

        $info = $loader->inspect($sourcePath);
        $width = $info->outputWidth();
        $height = $info->outputHeight();
        $layout = $generator->layout($width, $height, $rows, $cols);
        $capacity = $limits->workerCapacity($workers, count($layout->cells), $width, $height);
        if ($capacity < 2 || !$this->process->isAvailable()) {
            return $this->export($generator->generateFromFile($sourcePath, $rows, $cols), $directory, $options, $baseDirectory);
        }

        $builder = new ShapeBuilder($layout, $generator->getEdgeStyle() instanceof Geometry\BoundaryProfileProvider ? $generator->getEdgeStyle() : new EdgeStyle\NoEdgeStyle(), $generator->getGeometryOptions()->seed);
        $shapes = [];
        foreach ($layout->cells as $cell) {
            $shapes[$cell->id] = $builder->shape($cell);
        }
        $plan = OutputPlan::create($shapes, $rows, $cols, $options);
        $session = ExportSession::open($directory, $baseDirectory, $plan, $options);
        $workerDir = null;
        $workerMemory = [];
        try {
            $pending = $session->pendingIds();
            if ($pending !== []) {
                $workerDir = $session->root() . DIRECTORY_SEPARATOR . OutputPlan::RESERVED_PREFIX . '-workers-' . bin2hex(random_bytes(8));
                if (!@mkdir($workerDir, 0700)) {
                    throw new ExportFailedException('Failed to create worker result directory.');
                }
                $chunks = array_chunk($pending, max(1, (int) ceil(count($pending) / min($capacity, count($pending)))));
                $workerMemory = $this->runWorkers($chunks, $shapes, $sourcePath, $rows, $cols, $generator, $limits, $options, $session, $workerDir);
            }

            $geometryMeta = [
                'topology' => $layout->topology,
                'style' => PuzzleGenerator::styleName($generator->getEdgeStyle()),
                'seed' => $generator->getGeometryOptions()->seed,
                'samples' => $generator->getGeometryOptions()->samples,
                'version' => GeometryOptions::VERSION,
            ];
            $manifest = ManifestBuilder::applyHook(
                ManifestBuilder::build($plan, basename($sourcePath), $rows, $cols, $width, $height, $geometryMeta, $options),
                $options,
            );

            $after = $options->getOnPieceAfterSave();
            $parentPieces = null;
            $result = $session->publish($manifest, static function (string $id) use ($after, &$parentPieces, $generator, $sourcePath, $rows, $cols, $session): void {
                if ($after === null) {
                    return;
                }
                // Decode once in the parent, after all workers have exited.
                $parentPieces ??= self::piecesById($generator->generateFromFile($sourcePath, $rows, $cols));
                $after($parentPieces[$id] ?? throw new ExportFailedException("Unknown piece {$id}"), $session->destination($id));
            });
            $session->close();

            return $result->withWorkerMemory($workerMemory);
        } catch (\Throwable $e) {
            $session->abort();
            throw $e;
        } finally {
            if ($workerDir !== null && is_dir($workerDir)) {
                // Only the envelope files this export wrote; never a broader pattern.
                $envelopes = glob($workerDir . DIRECTORY_SEPARATOR . 'worker-*.json');
                $partial = glob($workerDir . DIRECTORY_SEPARATOR . 'worker-*.json.tmp');
                foreach ([...$envelopes === false ? [] : $envelopes, ...$partial === false ? [] : $partial] as $file) {
                    @unlink($file);
                }
                @rmdir($workerDir);
            }
        }
    }

    /**
     * @return array<string, Piece>
     */
    private static function piecesById(Puzzle $puzzle): array
    {
        $byId = [];
        foreach ($puzzle->getPieces() as $piece) {
            $byId[$piece->getId()] = $piece;
        }

        return $byId;
    }

    /**
     * @param list<list<string>> $chunks
     * @param array<string, PieceShape> $shapes
     * @return list<array{peakMemory: int, maxRss: int}> per-worker memory, in chunk order
     */
    private function runWorkers(
        array $chunks,
        array $shapes,
        string $sourcePath,
        int $rows,
        int $cols,
        PuzzleGenerator $generator,
        ResourceLimits $limits,
        ExportOptions $options,
        ExportSession $session,
        string $workerDir,
    ): array {
        /** @var array<int, int> $children pid => chunk index */
        $children = [];
        foreach ($chunks as $index => $chunk) {
            $pid = $this->process->fork();
            if ($pid === -1) {
                $this->killAndReap(array_keys($children));
                throw new ExportFailedException('Failed to fork worker ' . ($index + 1) . ' of ' . count($chunks) . '; started workers were terminated.');
            }
            if ($pid === 0) {
                $code = $this->runWorker($index, $chunk, $shapes, $sourcePath, $rows, $cols, $generator, $limits, $options, $session, $workerDir);
                $this->process->terminateChild($code);
            }
            $children[$pid] = $index;
        }

        $failures = [];
        $memory = [];
        foreach ($children as $pid => $index) {
            $status = $this->reap($pid);
            if ($status === null) {
                $failures[] = "worker {$index}: waitpid failed";
                continue;
            }
            $signal = $this->process->termSignal($status);
            if ($signal !== null) {
                $failures[] = "worker {$index}: killed by signal {$signal}";
                continue;
            }
            if (!$this->process->exitedNormally($status)) {
                $failures[] = "worker {$index}: abnormal termination";
                continue;
            }
            $usage = null;
            $error = $this->validateEnvelope($workerDir, $index, $chunks[$index], $session, $usage);
            if ($usage !== null) {
                $memory[$index] = $usage;
            }
            $code = $this->process->exitCode($status);
            if ($code !== 0) {
                $failures[] = "worker {$index}: exit code {$code}" . ($error !== null ? " ({$error})" : '');
            } elseif ($error !== null) {
                $failures[] = "worker {$index}: {$error}";
            }
        }
        if ($failures !== []) {
            throw new ExportFailedException("Parallel export failed; nothing was published:\n- " . implode("\n- ", $failures));
        }
        ksort($memory);

        return array_values($memory);
    }

    /**
     * Child entry point. Never returns into the caller's code path: every
     * Throwable is converted into an error envelope and an exit code.
     *
     * @param list<string> $chunk
     * @param array<string, PieceShape> $shapes
     */
    private function runWorker(
        int $index,
        array $chunk,
        array $shapes,
        string $sourcePath,
        int $rows,
        int $cols,
        PuzzleGenerator $generator,
        ResourceLimits $limits,
        ExportOptions $options,
        ExportSession $session,
        string $workerDir,
    ): int {
        try {
            $image = (new ImageLoader($limits))->loadFromFile($sourcePath);
            $indices = array_map(static fn (string $id): array => [$shapes[$id]->row, $shapes[$id]->col], $chunk);
            $before = $options->getOnPieceBeforeSave();
            $entries = [];
            foreach ($generator->generatePiecesFromGd($image, $rows, $cols, $indices) as $piece) {
                $id = $piece->getId();
                if ($before !== null) {
                    $before($piece, $session->destination($id));
                }
                $path = $session->stagingPath($id);
                ImageEncoder::write($piece->toGdImage(), $path, $options);
                $entries[] = ['id' => $id, 'filename' => basename($path), 'bytes' => (int) filesize($path)];
            }
            $envelope = ['version' => self::ENVELOPE_VERSION, 'ok' => true, 'worker' => $index, 'pieces' => $entries, 'memory' => self::selfMemory()];
            $code = 0;
        } catch (\Throwable $e) {
            $envelope = ['version' => self::ENVELOPE_VERSION, 'ok' => false, 'worker' => $index, 'error' => ['class' => $e::class, 'message' => $e->getMessage()]];
            $code = 1;
        }
        try {
            $json = $this->workerEnvelopeFilter !== null
                ? ($this->workerEnvelopeFilter)($envelope, $index)
                : json_encode($envelope, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            $target = $workerDir . DIRECTORY_SEPARATOR . "worker-{$index}.json";
            $tmp = $target . '.tmp';
            if (file_put_contents($tmp, $json) !== strlen($json) || !rename($tmp, $target)) {
                $code = 2;
            }
        } catch (\Throwable) {
            $code = 2;
        }

        return $code;
    }

    /**
     * Peak PHP memory and peak RSS of the current process, in bytes.
     * ru_maxrss is reported in bytes on macOS and kilobytes elsewhere.
     *
     * @return array{peakMemory: int, maxRss: int}
     */
    private static function selfMemory(): array
    {
        $usage = getrusage();
        $rss = is_array($usage) && is_int($usage['ru_maxrss'] ?? null) ? $usage['ru_maxrss'] : 0;

        return ['peakMemory' => memory_get_peak_usage(true), 'maxRss' => PHP_OS_FAMILY === 'Darwin' ? $rss : $rss * 1024];
    }

    /**
     * @param list<string> $chunk
     * @param ?array{peakMemory: int, maxRss: int} $memory receives the worker's reported memory usage
     */
    private function validateEnvelope(string $workerDir, int $index, array $chunk, ExportSession $session, ?array &$memory = null): ?string
    {
        $file = $workerDir . DIRECTORY_SEPARATOR . "worker-{$index}.json";
        if (!is_file($file)) {
            return 'missing result envelope';
        }
        $raw = (string) file_get_contents($file);
        if ($raw === '') {
            return 'empty result envelope';
        }
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'invalid JSON result envelope';
        }
        if (!is_array($data) || ($data['version'] ?? null) !== self::ENVELOPE_VERSION || ($data['worker'] ?? null) !== $index) {
            return 'malformed result envelope';
        }
        if (($data['ok'] ?? null) !== true) {
            $error = $data['error'] ?? null;
            $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : 'unknown error';
            $class = is_array($error) && is_string($error['class'] ?? null) ? $error['class'] : 'Error';

            return "{$class}: {$message}";
        }
        $pieces = $data['pieces'] ?? null;
        if (!is_array($pieces) || !array_is_list($pieces)) {
            return 'result envelope has no piece list';
        }
        $seen = [];
        foreach ($pieces as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || !is_int($entry['bytes'] ?? null)) {
                return 'malformed piece entry';
            }
            $id = $entry['id'];
            if (isset($seen[$id])) {
                return "duplicate result for piece {$id}";
            }
            if (!in_array($id, $chunk, true)) {
                return "unexpected piece {$id}";
            }
            $seen[$id] = true;
            if (($entry['filename'] ?? null) !== basename($session->stagingPath($id))) {
                return "wrong filename for piece {$id}";
            }
            try {
                $session->verifyStaged($id);
            } catch (ExportFailedException $e) {
                return $e->getMessage();
            }
            if ((int) filesize($session->stagingPath($id)) !== $entry['bytes']) {
                return "size mismatch for piece {$id}";
            }
        }
        $missing = array_diff($chunk, array_keys($seen));
        if ($missing !== []) {
            return 'missing pieces ' . implode(', ', $missing);
        }
        $reported = $data['memory'] ?? null;
        if (is_array($reported) && is_int($reported['peakMemory'] ?? null) && is_int($reported['maxRss'] ?? null)) {
            $memory = ['peakMemory' => $reported['peakMemory'], 'maxRss' => $reported['maxRss']];
        }

        return null;
    }

    private function reap(int $pid): ?int
    {
        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $result = $this->process->wait($pid);
            if ($result['pid'] === $pid) {
                return $result['status'];
            }
            if (!$result['interrupted']) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param list<int> $pids
     */
    private function killAndReap(array $pids): void
    {
        foreach ($pids as $pid) {
            $this->process->signal($pid, defined('SIGKILL') ? SIGKILL : 9);
        }
        foreach ($pids as $pid) {
            $this->reap($pid);
        }
    }
}
