<?php

declare(strict_types=1);

namespace ImagePuzzle;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Geometry\Topology\RectangularTopology;
use ImagePuzzle\Geometry\Topology\TopologyInterface;

/**
 * Geometry settings shared by sequential and parallel generation.
 *
 * - seed: selects tab directions and variations; identical seeds give
 *   identical outlines regardless of worker count or piece order.
 * - samples: antialiasing sub-samples per axis; 1 is the exact reference mode
 *   in which every source pixel belongs to exactly one piece.
 * - topology: board subdivision (rectangular by default; hexagonal and
 *   triangular topologies are available for jigsaw boards).
 */
final readonly class GeometryOptions
{
    public const DEFAULT_SEED = 1;
    public const DEFAULT_SAMPLES = 4;
    public const VERSION = 2;

    public TopologyInterface $topology;

    public function __construct(
        public int $seed = self::DEFAULT_SEED,
        public int $samples = self::DEFAULT_SAMPLES,
        ?TopologyInterface $topology = null,
    ) {
        if ($samples < 1 || $samples > 16) {
            throw new InvalidArgumentException('Geometry samples must be between 1 (exact) and 16.');
        }
        if ($seed < 0 || $seed > 2_147_483_647) {
            throw new InvalidArgumentException('Geometry seed must be between 0 and 2147483647.');
        }
        $this->topology = $topology ?? new RectangularTopology();
    }

    public static function exact(int $seed = self::DEFAULT_SEED, ?TopologyInterface $topology = null): self
    {
        return new self($seed, 1, $topology);
    }
}
