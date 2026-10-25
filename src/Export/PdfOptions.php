<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use ImagePuzzle\Exception\InvalidArgumentException;

/**
 * Settings of PdfExporter. Lengths are millimetres.
 *
 * - $paper/$orientation: A4 or A3; "auto" picks the orientation that prints
 *   the board largest (or on fewer sheets).
 * - $widthMm: printed width of the board. Null fits it to one sheet; a
 *   larger width tiles it over several sheets, cut along the margins.
 * - $reference: add a page with the whole solved board, its outlines and
 *   piece numbers, scaled to one sheet.
 * - $maxDpi: the image is downsampled to at most this resolution at the
 *   printed size; it is never upsampled.
 */
final readonly class PdfOptions
{
    public const PAPER = ['a4' => [210.0, 297.0], 'a3' => [297.0, 420.0]];

    public function __construct(
        public string $paper = 'a4',
        public string $orientation = 'auto',
        public float $marginMm = 10.0,
        public ?float $widthMm = null,
        public bool $cutLines = true,
        public float $lineWidthPt = 0.5,
        public string $lineColor = '#000000',
        public bool $reference = true,
        public int $maxDpi = 300,
        public int $jpegQuality = 90,
        public ?string $title = null,
    ) {
        if (!isset(self::PAPER[$paper])) {
            throw new InvalidArgumentException("Unknown paper \"{$paper}\"; use a4 or a3.");
        }
        if (!in_array($orientation, ['auto', 'portrait', 'landscape'], true)) {
            throw new InvalidArgumentException("Unknown orientation \"{$orientation}\"; use auto, portrait or landscape.");
        }
        if (!is_finite($marginMm) || $marginMm < 5 || $marginMm > 50) {
            throw new InvalidArgumentException('The margin must be between 5 and 50 mm.');
        }
        if ($widthMm !== null && (!is_finite($widthMm) || $widthMm < 20 || $widthMm > 5000)) {
            throw new InvalidArgumentException('The printed width must be between 20 and 5000 mm.');
        }
        if (!is_finite($lineWidthPt) || $lineWidthPt < 0.1 || $lineWidthPt > 5) {
            throw new InvalidArgumentException('The cut line width must be between 0.1 and 5 pt.');
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $lineColor) !== 1) {
            throw new InvalidArgumentException('The cut line colour must be #rrggbb.');
        }
        if ($maxDpi < 72 || $maxDpi > 1200) {
            throw new InvalidArgumentException('The image resolution limit must be between 72 and 1200 dpi.');
        }
        if ($jpegQuality < 10 || $jpegQuality > 100) {
            throw new InvalidArgumentException('The JPEG quality must be between 10 and 100.');
        }
    }

    /**
     * Paper size in mm for an orientation.
     *
     * @return array{0: float, 1: float} width and height
     */
    public function paperSize(string $orientation): array
    {
        [$w, $h] = self::PAPER[$this->paper];

        return $orientation === 'landscape' ? [$h, $w] : [$w, $h];
    }
}
