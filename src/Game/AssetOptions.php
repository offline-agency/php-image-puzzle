<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Export\ImageEncoder;
use ImagePuzzle\ExportOptions;

/**
 * Image output of DefinitionBuilder, separate from the game configuration.
 *
 * - $format/$quality: encoding of the generated images (jigsaw pieces and
 *   their alternatives; for the other modes, the board image). Null keeps the
 *   defaults: PNG pieces and the source file as uploaded.
 * - $resolutions: extra jigsaw piece sets by name => source width in pixels
 *   (see RESOLUTIONS). Sets as wide as the source or wider are skipped: the
 *   tile images already are the full resolution.
 * - $atlas: also pack the pieces of one set into a spritesheet: "full" for
 *   the tile images or the name of a resolution.
 */
final readonly class AssetOptions
{
    /** Named piece resolutions: width of the whole board in pixels. */
    public const RESOLUTIONS = ['thumbnail' => 320, 'mobile' => 1080, 'desktop' => 1920];

    public const ATLAS_FULL = 'full';
    public const DEFAULT_ATLAS_PADDING = 2;
    public const DEFAULT_ATLAS_MAX_SIZE = 8192;

    /** @var array<string, int> */
    public array $resolutions;

    /**
     * @param array<string, int>|list<string> $resolutions names of RESOLUTIONS, or name => width
     */
    public function __construct(
        public ?string $format = null,
        public ?int $quality = null,
        array $resolutions = [],
        public ?string $atlas = null,
        public int $atlasPadding = self::DEFAULT_ATLAS_PADDING,
        public int $atlasMaxSize = self::DEFAULT_ATLAS_MAX_SIZE,
    ) {
        if ($format !== null || $quality !== null) {
            // Validates the format and the quality range for it.
            new ExportOptions(format: $format ?? ExportOptions::FORMAT_PNG, quality: $quality);
        }
        $named = [];
        foreach ($resolutions as $key => $value) {
            if (is_int($key)) {
                if (!is_string($value) || !isset(self::RESOLUTIONS[$value])) {
                    throw new InvalidArgumentException('Unknown resolution "' . (is_scalar($value) ? (string) $value : get_debug_type($value)) . '"; use ' . implode(', ', array_keys(self::RESOLUTIONS)) . ' or name => width.');
                }
                $named[$value] = self::RESOLUTIONS[$value];
                continue;
            }
            if (!is_int($value) || $value < 16 || $value > 1 << 16) {
                throw new InvalidArgumentException("Resolution {$key} needs a width between 16 and 65536 pixels.");
            }
            if (preg_match(PieceResolution::NAME_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException("Resolution name \"{$key}\" must match " . PieceResolution::NAME_PATTERN . '.');
            }
            $named[$key] = $value;
        }
        if (count($named) > PieceImages::MAX_RESOLUTIONS) {
            throw new InvalidArgumentException('At most ' . PieceImages::MAX_RESOLUTIONS . ' resolutions can be generated.');
        }
        if (count(array_unique($named)) !== count($named)) {
            throw new InvalidArgumentException('Two resolutions have the same width.');
        }
        asort($named);
        $this->resolutions = $named;
        if ($atlas !== null && $atlas !== self::ATLAS_FULL && !isset($named[$atlas])) {
            throw new InvalidArgumentException("The atlas resolution \"{$atlas}\" is neither \"full\" nor one of the requested resolutions.");
        }
        if ($atlasPadding < 0 || $atlasPadding > 16) {
            throw new InvalidArgumentException('The atlas padding must be between 0 and 16 pixels.');
        }
        if ($atlasMaxSize < 64 || $atlasMaxSize > PieceAtlas::MAX_SIZE) {
            throw new InvalidArgumentException('The atlas size limit must be between 64 and ' . PieceAtlas::MAX_SIZE . ' pixels.');
        }
    }

    /** Whether anything beyond the default output was requested for the pieces. */
    public function hasPieceImages(): bool
    {
        return $this->resolutions !== [] || $this->atlas !== null;
    }

    public function exportOptions(ExportOptions $base): ExportOptions
    {
        return $this->format === null && $this->quality === null ? $base : $base->withFormat($this->format ?? $base->getFormat())->withQuality($this->quality);
    }

    /** Fails early with a CapabilityUnavailableException when GD cannot encode the format. */
    public function assertSupported(): void
    {
        ImageEncoder::assertSupported($this->format ?? ExportOptions::FORMAT_PNG);
    }
}
