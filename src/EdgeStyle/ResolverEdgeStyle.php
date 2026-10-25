<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\PieceContext;

/**
 * Chooses a raster effect per piece (for example rounded corners only on
 * border pieces). Boundaries stay rectangular: piece geometry is shared between
 * neighbours and cannot be decided per piece, so a resolver that returns an
 * interlocking profile style (JigsawStyle, ArcStyle, ...) or a composite is
 * rejected. Use CompositeEdgeStyle or a single profile style for geometry.
 */
final class ResolverEdgeStyle implements EdgeStyleInterface
{
    /**
     * @param \Closure(PieceContext): EdgeStyleInterface $resolver Returns the style to apply for the given piece context.
     */
    public function __construct(
        private readonly \Closure $resolver,
    ) {
    }

    public function apply(GdImage $piece, PieceContext $context): GdImage
    {
        $style = ($this->resolver)($context);
        if ($style instanceof ProfileEdgeStyle || $style instanceof CompositeEdgeStyle) {
            throw new InvalidArgumentException(sprintf(
                'ResolverEdgeStyle can only resolve raster effects; %s defines shared boundary geometry.',
                $style::class,
            ));
        }

        return $style->apply($piece, $context);
    }
}
