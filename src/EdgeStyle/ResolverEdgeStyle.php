<?php

declare(strict_types=1);

namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\PieceContext;

/**
 * Resolves the edge style per piece using PieceContext (e.g. by row/col or border vs inner).
 * Use this when you need a different style per piece rather than a single style for the whole puzzle.
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
        return $style->apply($piece, $context);
    }
}
