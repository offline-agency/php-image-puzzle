<?php

declare(strict_types=1);

namespace ImagePuzzle;

use GdImage;
use ImagePuzzle\Exception\GdNotAvailableException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\UnsupportedImageTypeException;

final class ImageLoader
{
    private const SUPPORTED_TYPES = [
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_GIF => 'imagecreatefromgif',
    ];

    public function __construct()
    {
        if (!extension_loaded('gd')) {
            throw new GdNotAvailableException('The GD extension is required but not loaded.');
        }
    }

    public function loadFromFile(string $path): GdImage
    {
        if ($path === '') {
            throw new InvalidArgumentException('Image path cannot be empty.');
        }
        if (!is_readable($path)) {
            throw new InvalidArgumentException("Image file is not readable: {$path}");
        }

        $type = $this->detectImageType($path);
        if ($type === false) {
            throw new UnsupportedImageTypeException("Could not determine image type: {$path}");
        }

        $loader = self::SUPPORTED_TYPES[$type] ?? null;
        if ($loader === null) {
            $typeName = $this->getTypeName($type);
            throw new UnsupportedImageTypeException("Unsupported image type: {$typeName}. Only PNG, JPEG, and GIF are supported.");
        }

        $image = @$loader($path);
        if ($image === false) {
            throw new UnsupportedImageTypeException("Failed to load image: {$path}");
        }

        return $image;
    }

    /**
     * Detect image type without emitting PHP notices when the file is invalid.
     */
    private function detectImageType(string $path): int|false
    {
        set_error_handler(static function (): bool {
            return true;
        }, E_WARNING | E_NOTICE);
        try {
            return exif_imagetype($path);
        } finally {
            restore_error_handler();
        }
    }

    private function getTypeName(int $type): string
    {
        return match ($type) {
            IMAGETYPE_GIF => 'GIF',
            IMAGETYPE_JPEG => 'JPEG',
            IMAGETYPE_PNG => 'PNG',
            default => "unknown ({$type})",
        };
    }
}
