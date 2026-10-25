<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Geometry\BoundaryProfileProvider;
use ImagePuzzle\Geometry\Profile\EdgeProfileInterface;
use ImagePuzzle\Geometry\Profile\FlatProfile;
use ImagePuzzle\Geometry\Topology\LayoutEdge;
use ImagePuzzle\PieceContext;

/**
 * Per-side boundary styles for rectangular boards.
 *
 * Every internal horizontal boundary is the bottom side of one piece and the
 * top side of the next, and every vertical boundary is a right side and a left
 * side. Compatibility is resolved once per boundary with this explicit policy:
 * top and bottom must name the same profile (it is used for all horizontal
 * boundaries) and left and right must name the same profile (used for all
 * vertical boundaries). Contradictory requests are rejected at construction.
 *
 * Accepted side styles: NoEdgeStyle, LineStyle and the profile styles
 * (JigsawStyle, ArcStyle, HalfCircleStyle, SinusStyle). Raster effects such as
 * RoundedCornersStyle have no per-side meaning and are rejected.
 */
final class CompositeEdgeStyle implements EdgeStyleInterface, BoundaryProfileProvider
{
    private readonly EdgeProfileInterface $horizontal;
    private readonly EdgeProfileInterface $vertical;

    public function __construct(
        private readonly EdgeStyleInterface $top,
        private readonly EdgeStyleInterface $right,
        private readonly EdgeStyleInterface $bottom,
        private readonly EdgeStyleInterface $left,
    ) {
        $this->horizontal = self::resolvePair('top', $top, 'bottom', $bottom);
        $this->vertical = self::resolvePair('left', $left, 'right', $right);
    }

    /**
     * @return array{top: EdgeStyleInterface, right: EdgeStyleInterface, bottom: EdgeStyleInterface, left: EdgeStyleInterface}
     */
    public function sides(): array
    {
        return ['top' => $this->top, 'right' => $this->right, 'bottom' => $this->bottom, 'left' => $this->left];
    }

    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        return $piece;
    }

    public function profileForBoundary(LayoutEdge $edge): EdgeProfileInterface
    {
        return match ($edge->orientation) {
            'horizontal' => $this->horizontal,
            'vertical' => $this->vertical,
            default => throw new InvalidArgumentException('CompositeEdgeStyle supports rectangular boards only.'),
        };
    }

    private static function resolvePair(string $nameA, EdgeStyleInterface $a, string $nameB, EdgeStyleInterface $b): EdgeProfileInterface
    {
        $profileA = self::profileOf($nameA, $a);
        $profileB = self::profileOf($nameB, $b);
        if ($profileA->name() !== $profileB->name()) {
            throw new InvalidArgumentException(sprintf(
                'Composite %s (%s) and %s (%s) describe the same shared boundaries and must use the same profile.',
                $nameA,
                $profileA->name(),
                $nameB,
                $profileB->name(),
            ));
        }

        return $profileA;
    }

    private static function profileOf(string $side, EdgeStyleInterface $style): EdgeProfileInterface
    {
        return match (true) {
            $style instanceof ProfileEdgeStyle => $style->profile(),
            $style instanceof NoEdgeStyle, $style instanceof LineStyle => new FlatProfile(),
            default => throw new InvalidArgumentException(sprintf(
                'Composite side "%s" must be no_edge, line, jigsaw, arc, half_circle or sinus; %s is not supported.',
                $side,
                $style::class,
            )),
        };
    }
}
