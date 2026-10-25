<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Portable deterministic generator shared with the TypeScript engine.
 *
 * Algorithm (docs/GAME_MODES.md, "Randomness"): the 32-bit state starts as
 * FNV-1a-32(stream) XOR seed, then each draw is one mulberry32 step. int(n)
 * returns floor(u * n / 2^32) for the 32-bit output u, which is exact in both
 * PHP integers and JavaScript doubles for n <= 2^20. Golden vectors in
 * conformance/prng.json keep both implementations identical.
 */
final class Prng
{
    public const MAX_SEED = 0xFFFFFFFF;
    public const MAX_RANGE = 1 << 20;

    private int $state;

    public function __construct(int $seed, string $stream)
    {
        if ($seed < 0 || $seed > self::MAX_SEED) {
            throw new InvalidArgumentException('Seed must be an unsigned 32-bit integer.');
        }
        $this->state = self::fnv1a($stream) ^ $seed;
    }

    public static function fnv1a(string $bytes): int
    {
        $hash = 0x811C9DC5;
        $length = strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            $hash = self::imul($hash ^ ord($bytes[$i]), 0x01000193);
        }

        return $hash;
    }

    /** Next unsigned 32-bit output. */
    public function nextUint32(): int
    {
        $this->state = ($this->state + 0x6D2B79F5) & 0xFFFFFFFF;
        $t = $this->state;
        $t = self::imul($t ^ ($t >> 15), $t | 1);
        $t = ($t ^ (($t + self::imul($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF)) & 0xFFFFFFFF;

        return ($t ^ ($t >> 14)) & 0xFFFFFFFF;
    }

    /** Integer in [0, $n). */
    public function int(int $n): int
    {
        if ($n < 1 || $n > self::MAX_RANGE) {
            throw new InvalidArgumentException('Range must be between 1 and ' . self::MAX_RANGE . '.');
        }

        return ($this->nextUint32() * $n) >> 32;
    }

    /**
     * Fisher-Yates from the last index down, drawing int(i + 1) at each step.
     *
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int($i + 1);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }

    /** Low 32 bits of a 32 x 32 bit product without float overflow. */
    private static function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;

        return (($a & 0xFFFF) * $b + (((($a >> 16) & 0xFFFF) * $b & 0xFFFF) << 16)) & 0xFFFFFFFF;
    }
}
