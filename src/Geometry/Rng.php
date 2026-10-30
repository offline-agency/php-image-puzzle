<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry;

/**
 * Deterministic, stateless-per-key random stream.
 *
 * Values depend only on (seed, key, index), never on global RNG state,
 * evaluation order, worker count or chunking. The algorithm is documented in
 * docs/GEOMETRY.md: value k is the first 52 bits of xxh64("seed|key|k")
 * divided by 2^52, giving a float in [0, 1).
 */
final class Rng
{
    private int $index = 0;

    public function __construct(
        private readonly int $seed,
        private readonly string $key,
    ) {
    }

    public function next(): float
    {
        $hex = hash('xxh64', $this->seed . '|' . $this->key . '|' . $this->index++);

        return hexdec(substr($hex, 0, 13)) / 4503599627370496.0;
    }

    public function range(float $min, float $max): float
    {
        return $min + ($max - $min) * $this->next();
    }

    public function sign(): int
    {
        return $this->next() < 0.5 ? -1 : 1;
    }

    /**
     * Integer in [0, $maxExclusive).
     */
    public function int(int $maxExclusive): int
    {
        return min($maxExclusive - 1, (int) floor($this->next() * $maxExclusive));
    }
}
