<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry\Profile;

use ImagePuzzle\Geometry\Rng;

/**
 * Two-dimensional shape of one shared boundary between two pieces.
 *
 * Coordinates are local to the edge: the first point is exactly (0, 0), the
 * last exactly ($length, 0); "along" runs from the start corner to the end
 * corner and "offset" is perpendicular to it. The builder mirrors the offset
 * with a seeded sign so either neighbour can receive the tab; the neighbour on
 * the other side receives the identical points reversed (the socket).
 *
 * Implementations must keep |offset| <= MAX_DEPTH * $scale and keep every
 * deviation from the straight line within the middle of the edge so that tabs
 * of different edges of the same piece cannot intersect.
 */
interface EdgeProfileInterface
{
    /** Maximum depth of a tab/socket as a fraction of the edge scale. */
    public const MAX_DEPTH = 0.26;

    /** Identifier used in manifests and configs (e.g. "jigsaw"). */
    public function name(): string;

    public function isFlat(): bool;

    /**
     * @param float $length edge length in pixels
     * @param float $scale size reference in pixels (<= $length), shared by both neighbours
     * @return list<array{0: float, 1: float}> [along, offset] points
     */
    public function points(float $length, float $scale, Rng $rng): array;
}
