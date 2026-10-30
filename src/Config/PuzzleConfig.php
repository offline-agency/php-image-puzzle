<?php

declare(strict_types=1);

namespace ImagePuzzle\Config;

use ImagePuzzle\EdgeStyle\EdgeStyleFactory;
use ImagePuzzle\EdgeStyle\EdgeStyleInterface;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\Geometry\Topology\HexagonalTopology;
use ImagePuzzle\Geometry\Topology\RectangularTopology;
use ImagePuzzle\Geometry\Topology\TopologyInterface;
use ImagePuzzle\Geometry\Topology\TriangularTopology;
use ImagePuzzle\GeometryOptions;

/**
 * Versioned, reproducible generation config (docs/schemas/v2/puzzle-config.schema.json).
 *
 * Version 2 configs are validated strictly: unknown keys and wrongly typed
 * values are rejected with the offending path. Configs without
 * "configVersion" are read as 1.x configs and migrated; every lossy step of
 * the migration is reported in warnings() instead of being dropped silently.
 * toArray() returns the normalised v2 form, so saving and reloading a config
 * reproduces the same geometry and assets.
 */
final readonly class PuzzleConfig
{
    public const VERSION = 2;
    public const SCHEMA_ID = 'https://raw.githubusercontent.com/offline-agency/php-image-puzzle/HEAD/docs/schemas/v2/puzzle-config.schema.json';

    private const TOPOLOGIES = ['rect', 'hex', 'triangle'];
    private const OUTPUT_KEYS = ['directory', 'manifestFilename', 'filenamePattern', 'format', 'quality', 'overwrite', 'publishMode', 'includePaths'];

    /**
     * @param array<string, mixed> $edge normalised edge object
     * @param array{directory?: string, manifestFilename?: string, filenamePattern?: string, format?: string, quality?: int, overwrite?: bool, publishMode?: string, includePaths?: bool} $output
     * @param list<string> $warnings
     */
    private function __construct(
        public ?string $input,
        public int $rows,
        public int $cols,
        public array $edge,
        public int $seed,
        public int $samples,
        public string $topology,
        public array $output,
        private array $warnings,
    ) {
    }

    /**
     * @param array<mixed> $data decoded JSON object
     */
    public static function fromArray(array $data): self
    {
        $warnings = [];
        if (!array_key_exists('configVersion', $data)) {
            $data = self::migrateV1($data, $warnings);
        } elseif ($data['configVersion'] !== self::VERSION) {
            throw new InvalidArgumentException('configVersion must be 2 (omit it only in legacy 1.x configs).');
        }

        self::assertKeys($data, ['$schema', 'configVersion', 'input', 'rows', 'cols', 'edge', 'geometry', 'output'], 'config');
        $input = $data['input'] ?? null;
        if ($input !== null && (!is_string($input) || $input === '')) {
            throw new InvalidArgumentException('config.input must be a non-empty string.');
        }
        $rows = self::positiveInt($data, 'rows', 'config.rows');
        $cols = self::positiveInt($data, 'cols', 'config.cols');
        if (!isset($data['edge']) || !is_array($data['edge'])) {
            throw new InvalidArgumentException('config.edge must be an object.');
        }
        $edge = EdgeStyleFactory::normalize($data['edge']);

        $geometry = $data['geometry'] ?? [];
        if (!is_array($geometry)) {
            throw new InvalidArgumentException('config.geometry must be an object.');
        }
        self::assertKeys($geometry, ['seed', 'samples', 'topology'], 'config.geometry');
        $seed = self::intOr($geometry, 'seed', GeometryOptions::DEFAULT_SEED, 'config.geometry.seed');
        $samples = self::intOr($geometry, 'samples', GeometryOptions::DEFAULT_SAMPLES, 'config.geometry.samples');
        $topology = $geometry['topology'] ?? 'rect';
        if (!is_string($topology) || !in_array($topology, self::TOPOLOGIES, true)) {
            throw new InvalidArgumentException('config.geometry.topology must be one of ' . implode(', ', self::TOPOLOGIES) . '.');
        }
        // Validate ranges once, with the same rules generation uses.
        new GeometryOptions($seed, $samples);

        $output = self::output($data['output'] ?? []);

        return new self($input, $rows, $cols, $edge, $seed, $samples, $topology, $output, $warnings);
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Config file is not readable: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InvalidArgumentException("Failed to read config file: {$path}");
        }

        return self::fromJson($raw);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException("Invalid JSON in config: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new InvalidArgumentException('Invalid config: expected a JSON object.');
        }

        return self::fromArray($data);
    }

    /**
     * Migration notes for legacy configs; empty for v2 input.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function edgeStyle(): EdgeStyleInterface
    {
        return EdgeStyleFactory::fromEdge($this->edge);
    }

    public function geometryOptions(): GeometryOptions
    {
        return new GeometryOptions($this->seed, $this->samples, self::topologyFor($this->topology));
    }

    /**
     * Export options for the configured output. Hooks are code, not config:
     * pass them on the returned object with the with*() methods.
     */
    public function exportOptions(): ExportOptions
    {
        $o = $this->output;

        return new ExportOptions(
            format: $o['format'] ?? ExportOptions::FORMAT_PNG,
            quality: $o['quality'] ?? null,
            filenamePattern: $o['filenamePattern'] ?? ExportOptions::DEFAULT_PATTERN,
            overwrite: $o['overwrite'] ?? true,
            manifestFilename: $o['manifestFilename'] ?? ExportOptions::DEFAULT_MANIFEST_FILENAME,
            publishMode: $o['publishMode'] ?? ExportOptions::PUBLISH_IN_PLACE,
            includePaths: $o['includePaths'] ?? true,
        );
    }

    public function outputDirectory(): ?string
    {
        return $this->output['directory'] ?? null;
    }

    public static function topologyFor(string $name): TopologyInterface
    {
        return match ($name) {
            'rect' => new RectangularTopology(),
            'hex' => new HexagonalTopology(),
            'triangle' => new TriangularTopology(),
            default => throw new InvalidArgumentException("Unknown topology: {$name}."),
        };
    }

    /**
     * Normalised v2 representation (defaults made explicit).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['$schema' => self::SCHEMA_ID, 'configVersion' => self::VERSION];
        if ($this->input !== null) {
            $out['input'] = $this->input;
        }
        $out += [
            'rows' => $this->rows,
            'cols' => $this->cols,
            'edge' => $this->edge,
            'geometry' => ['seed' => $this->seed, 'samples' => $this->samples, 'topology' => $this->topology],
        ];
        if ($this->output !== []) {
            $out['output'] = $this->output;
        }

        return $out;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $warnings
     * @return array<mixed>
     */
    private static function migrateV1(array $data, array &$warnings): array
    {
        $warnings[] = 'configVersion is missing: the config was read as a 1.x config and migrated to version 2.';
        if (isset($data['edge']) && is_array($data['edge'])) {
            $edge = $data['edge'];
            $type = $edge['type'] ?? null;
            if (isset($edge['options']) && is_array($edge['options'])) {
                $keep = $type === 'rounded_corners' ? ['radius'] : [];
                $dropped = array_diff(array_map('strval', array_keys($edge['options'])), $keep);
                if ($dropped !== []) {
                    $warnings[] = 'edge.options ' . implode(', ', $dropped) . ' had no effect in 1.x and were removed.';
                }
                $edge['options'] = array_intersect_key($edge['options'], array_flip($keep));
                if ($edge['options'] === []) {
                    unset($edge['options']);
                }
            }
            $data['edge'] = $edge;
        }
        if (isset($data['output']) && is_array($data['output'])) {
            $dropped = array_diff(array_map('strval', array_keys($data['output'])), self::OUTPUT_KEYS);
            if ($dropped !== []) {
                $warnings[] = 'output keys ' . implode(', ', $dropped) . ' are not part of version 2 and were removed.';
            }
            $data['output'] = array_intersect_key($data['output'], array_flip(self::OUTPUT_KEYS));
        }
        $data['geometry'] = ['seed' => GeometryOptions::DEFAULT_SEED, 'samples' => GeometryOptions::DEFAULT_SAMPLES, 'topology' => 'rect'];
        $warnings[] = 'Pieces now have real interlocking outlines; geometry defaults (seed 1, samples 4, rect) were applied.';
        $data['configVersion'] = self::VERSION;

        return $data;
    }

    /**
     * @return array{directory?: string, manifestFilename?: string, filenamePattern?: string, format?: string, quality?: int, overwrite?: bool, publishMode?: string, includePaths?: bool}
     */
    private static function output(mixed $output): array
    {
        if (!is_array($output)) {
            throw new InvalidArgumentException('config.output must be an object.');
        }
        self::assertKeys($output, self::OUTPUT_KEYS, 'config.output');
        $normalized = [];
        foreach (['directory', 'manifestFilename', 'filenamePattern', 'format', 'publishMode'] as $key) {
            if (array_key_exists($key, $output)) {
                if (!is_string($output[$key]) || $output[$key] === '') {
                    throw new InvalidArgumentException("config.output.{$key} must be a non-empty string.");
                }
                $normalized[$key] = $output[$key];
            }
        }
        if (array_key_exists('quality', $output)) {
            if (!is_int($output['quality'])) {
                throw new InvalidArgumentException('config.output.quality must be an integer.');
            }
            $normalized['quality'] = $output['quality'];
        }
        foreach (['overwrite', 'includePaths'] as $key) {
            if (array_key_exists($key, $output)) {
                if (!is_bool($output[$key])) {
                    throw new InvalidArgumentException("config.output.{$key} must be a boolean.");
                }
                $normalized[$key] = $output[$key];
            }
        }
        // Reuse ExportOptions validation (formats, quality ranges, filenames).
        try {
            new ExportOptions(
                format: $normalized['format'] ?? ExportOptions::FORMAT_PNG,
                quality: $normalized['quality'] ?? null,
                filenamePattern: $normalized['filenamePattern'] ?? ExportOptions::DEFAULT_PATTERN,
                manifestFilename: $normalized['manifestFilename'] ?? ExportOptions::DEFAULT_MANIFEST_FILENAME,
                publishMode: $normalized['publishMode'] ?? ExportOptions::PUBLISH_IN_PLACE,
            );
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('config.output: ' . $e->getMessage(), 0, $e);
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $data
     */
    private static function positiveInt(array $data, string $key, string $path): int
    {
        if (!isset($data[$key]) || !is_int($data[$key]) || $data[$key] < 1) {
            throw new InvalidArgumentException("{$path} must be an integer of at least 1.");
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     */
    private static function intOr(array $data, string $key, int $default, string $path): int
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        if (!is_int($data[$key])) {
            throw new InvalidArgumentException("{$path} must be an integer.");
        }

        return $data[$key];
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $allowed
     */
    private static function assertKeys(array $data, array $allowed, string $path): void
    {
        $unknown = array_diff(array_map('strval', array_keys($data)), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException("{$path} has unsupported keys: " . implode(', ', $unknown) . '.');
        }
    }
}
