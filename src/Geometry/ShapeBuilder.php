<?php

declare(strict_types=1);

namespace ImagePuzzle\Geometry;

use ImagePuzzle\Geometry\Profile\EdgeProfileInterface;
use ImagePuzzle\Geometry\Topology\Layout;
use ImagePuzzle\Geometry\Topology\LayoutCell;
use ImagePuzzle\Geometry\Topology\LayoutEdge;

/**
 * Turns a layout plus boundary profiles into piece outlines.
 *
 * Each internal boundary is computed once from (seed, edge key) and shared:
 * the forward cell walks its points forwards, the reverse cell walks the very
 * same float points backwards. Outlines therefore partition the board exactly
 * and do not depend on which pieces are built, or in which order.
 */
final class ShapeBuilder
{
    /** @var array<string, list<array{0: float, 1: float}>> */
    private array $edgePoints = [];

    public function __construct(
        private readonly Layout $layout,
        private readonly BoundaryProfileProvider $profiles,
        private readonly int $seed,
    ) {
    }

    public function layout(): Layout
    {
        return $this->layout;
    }

    public function shape(LayoutCell $cell): PieceShape
    {
        $polygon = [];
        $border = false;
        foreach ($cell->edges as [$key, $reversed]) {
            $edge = $this->layout->edge($key);
            $border = $border || $edge->isExternal();
            $points = $this->edgeWorldPoints($edge);
            if ($reversed) {
                $points = array_reverse($points);
            }
            // Drop the end point: it is the start point of the next edge.
            array_pop($points);
            foreach ($points as $point) {
                $polygon[] = $point;
            }
        }

        return new PieceShape(
            $cell->id,
            $cell->row,
            $cell->col,
            $cell->logical,
            PieceShape::renderedBounds($polygon, $this->layout->width, $this->layout->height),
            $polygon,
            $border,
        );
    }

    public function profileName(LayoutEdge $edge): string
    {
        return $edge->isExternal() ? 'flat' : $this->profiles->profileForBoundary($edge)->name();
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    public function edgeWorldPoints(LayoutEdge $edge): array
    {
        if (isset($this->edgePoints[$edge->key])) {
            return $this->edgePoints[$edge->key];
        }
        [$ax, $ay] = $edge->a;
        [$bx, $by] = $edge->b;
        if ($edge->isExternal()) {
            return $this->edgePoints[$edge->key] = [[$ax, $ay], [$bx, $by]];
        }

        $profile = $this->profiles->profileForBoundary($edge);
        $length = $edge->length();
        $rng = new Rng($this->seed, $edge->key);
        $sign = $rng->sign();
        $local = $profile->points($length, min($edge->scale, $length), $rng);
        self::assertProfile($profile, $local, $length, $edge->scale);

        $dx = ($bx - $ax) / $length;
        $dy = ($by - $ay) / $length;
        // Normal rotated 90 degrees from the travel direction.
        $nx = -$dy * $sign;
        $ny = $dx * $sign;
        $world = [];
        $last = count($local) - 1;
        foreach ($local as $i => [$u, $v]) {
            if ($i === 0) {
                $world[] = [$ax, $ay];
            } elseif ($i === $last) {
                $world[] = [$bx, $by];
            } else {
                $world[] = [$ax + $u * $dx + $v * $nx, $ay + $u * $dy + $v * $ny];
            }
        }

        return $this->edgePoints[$edge->key] = $world;
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     */
    private static function assertProfile(EdgeProfileInterface $profile, array $points, float $length, float $scale): void
    {
        $limit = EdgeProfileInterface::MAX_DEPTH * $scale + 1e-9;
        foreach ($points as [$u, $v]) {
            if (abs($v) > $limit || $u < -1e-9 || $u > $length + 1e-9) {
                throw new \LogicException(sprintf('Profile "%s" leaves its allowed envelope.', $profile->name()));
            }
        }
    }
}
