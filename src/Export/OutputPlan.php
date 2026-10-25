<?php

declare(strict_types=1);

namespace ImagePuzzle\Export;

use ImagePuzzle\Exception\ExportFailedException;
use ImagePuzzle\ExportOptions;
use ImagePuzzle\Geometry\PieceShape;

/**
 * Every destination filename of an export, resolved and validated before the
 * first byte is written: duplicates (also case-insensitively, for macOS and
 * Windows filesystems), piece/manifest collisions, reserved names and
 * extension/format mismatches are rejected.
 */
final readonly class OutputPlan
{
    public const RESERVED_PREFIX = '.image-puzzle';

    /**
     * @param array<string, string> $filenames piece id -> filename
     * @param array<string, PieceShape> $shapes piece id -> shape
     */
    private function __construct(
        public array $filenames,
        public array $shapes,
        public string $manifestFilename,
    ) {
    }

    /**
     * @param iterable<PieceShape> $shapes
     */
    public static function create(iterable $shapes, int $rows, int $cols, ExportOptions $options): self
    {
        $filenames = [];
        $byShape = [];
        $owners = [];
        $manifest = basename($options->getManifestFilename());
        $owners[strtolower($manifest)] = 'the manifest';

        foreach ($shapes as $shape) {
            $name = $options->resolvePieceFilename($shape->row, $shape->col, $rows, $cols, $shape->id);
            if ($name === '' || $name === '.' || $name === '..' || $name !== basename($name) || str_contains($name, '\\') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
                throw new ExportFailedException("Invalid piece filename for {$shape->id}: '{$name}'.");
            }
            if (str_starts_with($name, self::RESERVED_PREFIX) || $name === 'current.json' || $name === 'generations') {
                throw new ExportFailedException("Piece filename '{$name}' uses a reserved name.");
            }
            if (!$options->extensionMatchesFormat($name)) {
                throw new ExportFailedException("Piece filename '{$name}' does not match the {$options->getFormat()} format.");
            }
            $key = strtolower($name);
            if (isset($owners[$key])) {
                throw new ExportFailedException("Piece {$shape->id} would write '{$name}', already used by {$owners[$key]}. Use {row}/{col}, {index} or {id} in the filename pattern.");
            }
            $owners[$key] = "piece {$shape->id}";
            $filenames[$shape->id] = $name;
            $byShape[$shape->id] = $shape;
        }

        return new self($filenames, $byShape, $manifest);
    }
}
