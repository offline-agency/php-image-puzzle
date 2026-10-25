<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Settings of SvgExporter.
 *
 * - $image: "link" references the source image by its relative path (keep
 *   the SVG next to it), "embed" inlines it as a data URI, "none" draws
 *   outlines only.
 * - $pieces: one clipped copy of the image per piece (the reconstruction);
 *   $outlines: the cut lines on top.
 * - $widthMm: physical width for printing; null sizes the SVG in pixels.
 * - $title/$description: plain text, escaped and stripped of characters
 *   that XML does not allow.
 */
final readonly class SvgOptions
{
    public const IMAGE_LINK = 'link';
    public const IMAGE_EMBED = 'embed';
    public const IMAGE_NONE = 'none';

    public const MAX_TEXT = 1000;

    public function __construct(
        public string $image = self::IMAGE_LINK,
        public bool $pieces = true,
        public bool $outlines = true,
        public string $stroke = '#000000',
        public float $strokeWidth = 1.0,
        public ?float $widthMm = null,
        public ?string $title = null,
        public ?string $description = null,
        public int $precision = 2,
    ) {
        if (!in_array($image, [self::IMAGE_LINK, self::IMAGE_EMBED, self::IMAGE_NONE], true)) {
            throw new InvalidArgumentException("Unknown SVG image mode \"{$image}\"; use link, embed or none.");
        }
        if (!$pieces && !$outlines) {
            throw new InvalidArgumentException('An SVG needs pieces, outlines or both.');
        }
        if ($image === self::IMAGE_NONE && $pieces) {
            throw new InvalidArgumentException('Pieces need the image: use image "link" or "embed", or outlines only.');
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $stroke) !== 1) {
            throw new InvalidArgumentException('The stroke colour must be #rrggbb.');
        }
        if (!is_finite($strokeWidth) || $strokeWidth <= 0 || $strokeWidth > 100) {
            throw new InvalidArgumentException('The stroke width must be between 0 and 100.');
        }
        if ($widthMm !== null && (!is_finite($widthMm) || $widthMm < 1 || $widthMm > 10000)) {
            throw new InvalidArgumentException('The physical width must be between 1 and 10000 mm.');
        }
        if ($precision < 0 || $precision > 6) {
            throw new InvalidArgumentException('The coordinate precision must be between 0 and 6 decimals.');
        }
    }
}
