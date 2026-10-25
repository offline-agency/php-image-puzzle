<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Builds an EdgeStyleInterface from a config array (docs/schemas/v2/edge.schema.json).
 *
 * Values are validated, never coerced: a non-integer radius, unknown keys,
 * options on styles that take none, or sides on a non-composite style are
 * rejected with the offending path in the message.
 */
final class EdgeStyleFactory
{
    private const DEFAULT_ROUNDED_CORNERS_RADIUS = 4;
    private const SIDE_TYPES = ['no_edge', 'line', 'jigsaw', 'arc', 'half_circle', 'sinus'];
    private const SIDES = ['top', 'right', 'bottom', 'left'];

    /**
     * Build edge style from a config array containing an "edge" object.
     *
     * @param array<mixed> $config
     * @throws InvalidArgumentException When edge config is missing, type is unknown, or options are invalid.
     */
    public static function fromArray(array $config): EdgeStyleInterface
    {
        if (!isset($config['edge']) || !is_array($config['edge'])) {
            throw new InvalidArgumentException('Config must contain an "edge" object.');
        }

        return self::fromEdge($config['edge']);
    }

    /**
     * Build edge style from the "edge" object itself.
     *
     * @param array<mixed> $edge
     */
    public static function fromEdge(array $edge, string $path = 'edge'): EdgeStyleInterface
    {
        if (!isset($edge['type']) || !is_string($edge['type'])) {
            throw new InvalidArgumentException('Edge config must contain a "type" string.');
        }
        $type = $edge['type'];
        $allowed = $type === 'composite' ? ['type', ...self::SIDES] : ['type', 'options'];
        self::assertKeys($edge, $allowed, $path);

        if ($type === 'composite') {
            return self::createComposite($edge, $path);
        }

        $options = $edge['options'] ?? [];
        if (!is_array($options)) {
            throw new InvalidArgumentException("{$path}.options must be an object.");
        }

        return self::createFromType($type, $options, $path);
    }

    /**
     * Normalised representation of a style built from config, suitable for
     * saving a reproducible configuration.
     *
     * @param array<mixed> $edge
     * @return array<string, mixed>
     */
    public static function normalize(array $edge): array
    {
        $style = self::fromEdge($edge);
        if ($style instanceof RoundedCornersStyle) {
            return ['type' => 'rounded_corners', 'options' => ['radius' => $style->getRadius()]];
        }
        if ($style instanceof CompositeEdgeStyle) {
            $out = ['type' => 'composite'];
            foreach (self::SIDES as $side) {
                $spec = $edge[$side] ?? ['type' => 'no_edge'];
                $out[$side] = ['type' => is_array($spec) && is_string($spec['type'] ?? null) ? $spec['type'] : 'no_edge'];
            }

            return $out;
        }

        return ['type' => (string) $edge['type']];
    }

    /**
     * @param array<mixed> $edge Edge config with type "composite" and top, right, bottom, left
     */
    private static function createComposite(array $edge, string $path): EdgeStyleInterface
    {
        $styles = [];
        foreach (self::SIDES as $side) {
            $spec = $edge[$side] ?? ['type' => 'no_edge'];
            if (!is_array($spec)) {
                throw new InvalidArgumentException("{$path}.{$side} must be an object.");
            }
            $styles[$side] = self::createFromEdgeSpec($spec, "{$path}.{$side}");
        }

        return new CompositeEdgeStyle($styles['top'], $styles['right'], $styles['bottom'], $styles['left']);
    }

    /**
     * @param array<mixed> $spec Must have "type" (string).
     */
    private static function createFromEdgeSpec(array $spec, string $path): EdgeStyleInterface
    {
        if (!isset($spec['type']) || !is_string($spec['type'])) {
            throw new InvalidArgumentException('Edge spec must contain a "type" string.');
        }
        if ($spec['type'] === 'composite') {
            throw new InvalidArgumentException('Nested composite edge type is not supported.');
        }
        if (!in_array($spec['type'], self::SIDE_TYPES, true)) {
            throw new InvalidArgumentException("{$path}.type must be one of " . implode(', ', self::SIDE_TYPES) . "; got \"{$spec['type']}\".");
        }
        self::assertKeys($spec, ['type'], $path);

        return self::createFromType($spec['type'], [], $path);
    }

    /**
     * Build edge style from a JSON config file.
     *
     * @throws InvalidArgumentException When file cannot be read, JSON is invalid, or edge config is invalid.
     */
    public static function fromFile(string $path): EdgeStyleInterface
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException("Config file is not readable: {$path}");
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== 'json') {
            throw new InvalidArgumentException('Only .json config files are supported.');
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new InvalidArgumentException("Failed to read config file: {$path}");
        }

        try {
            $config = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException("Invalid JSON in config file: {$e->getMessage()}", 0, $e);
        }
        if (!is_array($config)) {
            throw new InvalidArgumentException('Invalid JSON in config file: expected an object.');
        }

        return self::fromArray($config);
    }

    /**
     * @param array<mixed> $options
     */
    private static function createFromType(string $type, array $options, string $path): EdgeStyleInterface
    {
        if ($type !== 'rounded_corners' && $options !== []) {
            throw new InvalidArgumentException("{$path}.options is not supported for edge type \"{$type}\".");
        }

        return match ($type) {
            'no_edge' => new NoEdgeStyle(),
            'line' => new LineStyle(),
            'jigsaw' => new JigsawStyle(),
            'arc' => new ArcStyle(),
            'half_circle' => new HalfCircleStyle(),
            'sinus' => new SinusStyle(),
            'rounded_corners' => new RoundedCornersStyle(self::radius($options, $path)),
            default => throw new InvalidArgumentException("Unknown edge type: {$type}."),
        };
    }

    /**
     * @param array<mixed> $options
     */
    private static function radius(array $options, string $path): int
    {
        self::assertKeys($options, ['radius'], "{$path}.options");
        if (!array_key_exists('radius', $options)) {
            return self::DEFAULT_ROUNDED_CORNERS_RADIUS;
        }
        if (!is_int($options['radius'])) {
            throw new InvalidArgumentException("{$path}.options.radius must be an integer.");
        }

        return $options['radius'];
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
