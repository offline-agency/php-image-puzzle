<?php

declare(strict_types=1);

namespace ImagePuzzle;

/**
 * Metadata read from an image header without decoding the pixels.
 */
final readonly class ImageInfo
{
    /**
     * @param string $type "png", "jpeg", "gif" or "webp"
     * @param int $frames number of frames (GIF animation frames; 1 for other formats)
     * @param int|null $orientation EXIF orientation 1..8 for JPEG when present
     */
    public function __construct(
        public string $type,
        public int $width,
        public int $height,
        public int $bytes,
        public int $frames = 1,
        public ?int $orientation = null,
    ) {
    }

    public function isAnimated(): bool
    {
        return $this->frames > 1;
    }

    /** Whether applying the orientation swaps width and height. */
    public function orientationSwapsAxes(): bool
    {
        return $this->orientation !== null && $this->orientation >= 5;
    }
}
