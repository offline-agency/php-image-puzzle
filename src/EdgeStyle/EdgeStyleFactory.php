<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Builds an EdgeStyleInterface from a config array (decoded YAML/JSON).
 */
final class EdgeStyleFactory
{
    private const DEFAULT_ROUNDED_CORNERS_RADIUS = 4;

    /**
     * Build edge style from a config array.
     *
     * @param array<string, mixed> $config Must contain "edge" with "type" (string). Optional "edge.options" for style params.
     * @throws InvalidArgumentException When edge config is missing, type is unknown, or options are invalid.
     */
    public static function fromArray(array $config): EdgeStyleInterface
    {
        if (!isset($config['edge']) || !is_array($config['edge'])) {
            throw new InvalidArgumentException('Config must contain an "edge" object.');
        }

        $edge = $config['edge'];

        if (!isset($edge['type']) || !is_string($edge['type'])) {
            throw new InvalidArgumentException('Edge config must contain a "type" string.');
        }

        $options = isset($edge['options']) && is_array($edge['options']) ? $edge['options'] : [];

        return self::createFromType($edge['type'], $options);
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

        $config = json_decode($raw, true);
        if (!is_array($config)) {
            $msg = json_last_error_msg();
            throw new InvalidArgumentException("Invalid JSON in config file: {$msg}");
        }

        return self::fromArray($config);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function createFromType(string $type, array $options): EdgeStyleInterface
    {
        return match ($type) {
            'no_edge' => new NoEdgeStyle(),
            'line' => new LineStyle(),
            'jigsaw' => new JigsawStyle(),
            'arc' => new ArcStyle(),
            'half_circle' => new HalfCircleStyle(),
            'sinus' => new SinusStyle(),
            'rounded_corners' => new RoundedCornersStyle(
                isset($options['radius'])
                    ? (int) $options['radius']
                    : self::DEFAULT_ROUNDED_CORNERS_RADIUS,
            ),
            default => throw new InvalidArgumentException("Unknown edge type: {$type}."),
        };
    }
}
