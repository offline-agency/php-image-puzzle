<?php

declare(strict_types=1);

namespace ImagePuzzle\Game;

use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Game\Rules\JigsawRules;
use ImagePuzzle\Geometry\Bounds;

/**
 * Builds game definitions from presets or explicit custom parameters.
 *
 * Grid modes (sliding, swap, rotation, strips) play on equal cells: the
 * remainder pixels that do not divide evenly are cropped symmetrically
 * (extra pixel on the right/bottom) and recorded in board.crop. Rotation with
 * quarter turns additionally crops to square cells.
 */
final class GameFactory
{
    private const ASSISTANCE_DEFAULT = ['preview' => true, 'numbers' => false, 'highlightCorrect' => false];
    private const CUSTOM_KEYS = [
        'sliding' => ['rows', 'cols', 'scrambleMoves'],
        'swap' => ['rows', 'cols'],
        'rotation' => ['rows', 'cols', 'orientations'],
        'strips' => ['count', 'axis'],
    ];

    /**
     * @param array<string, mixed> $custom parameters when $preset is "custom" (see docs/GAME_MODES.md)
     */
    public function create(GameKind $kind, SourceImage $source, string $image, string $preset = 'medium', array $custom = [], int $seed = 0): GameDefinition
    {
        if ($kind === GameKind::Jigsaw) {
            throw new InvalidArgumentException('Jigsaw definitions are built from an export manifest: use jigsawFromManifest().');
        }
        $params = $this->parameters($kind, $preset, $custom);
        $assistance = self::assistance($params['assistance'] ?? self::ASSISTANCE_DEFAULT);

        return match ($kind) {
            GameKind::Sliding => $this->grid($kind, $source, $image, $preset, $seed, self::side($params, 'rows', 2, 16), self::side($params, 'cols', 2, 16), $assistance, false, static fn (array $tiles): array => [
                'empty' => $tiles[count($tiles) - 1]->id,
                'scrambleMoves' => self::side($params, 'scrambleMoves', 1, 100000),
            ]),
            GameKind::Swap => $this->grid($kind, $source, $image, $preset, $seed, self::side($params, 'rows', 1, 16), self::side($params, 'cols', 1, 16), $assistance, false, static fn (): array => []),
            GameKind::Rotation => $this->rotation($source, $image, $preset, $seed, $params, $assistance),
            GameKind::Strips => $this->strips($source, $image, $preset, $seed, $params, $assistance),
            default => throw new InvalidArgumentException("The {$kind->value} mode has its own factory."),
        };
    }

    /**
     * Wraps an export manifest (version 2) as a jigsaw definition. Piece
     * images stay where the exporter wrote them; $imageBase is prefixed to
     * each manifest filename (for example "pieces/").
     *
     * @param array<string, mixed> $manifest
     */
    public function jigsawFromManifest(array $manifest, string $image, string $preset = 'medium', int $seed = 0, ?string $sourceSha256 = null, string $imageBase = '', ?int $snap = null): GameDefinition
    {
        if (($manifest['manifestVersion'] ?? null) !== 2) {
            throw new InvalidDefinitionException('A jigsaw definition needs a version 2 manifest.');
        }
        $width = Doc::positive($manifest, 'sourceWidth', 'manifest', 1 << 20);
        $height = Doc::positive($manifest, 'sourceHeight', 'manifest', 1 << 20);
        $geometry = Doc::object($manifest['geometry'] ?? null, 'manifest.geometry');
        $tiles = [];
        $minSide = PHP_INT_MAX;
        $maxW = 1;
        $maxH = 1;
        foreach (Doc::list($manifest['pieces'] ?? null, 'manifest.pieces', GameDefinition::MAX_TILES) as $i => $value) {
            $piece = Doc::object($value, "manifest.pieces[{$i}]");
            $rect = new Bounds(
                Doc::int($piece, 'x', "manifest.pieces[{$i}]"),
                Doc::int($piece, 'y', "manifest.pieces[{$i}]"),
                Doc::positive($piece, 'width', "manifest.pieces[{$i}]"),
                Doc::positive($piece, 'height', "manifest.pieces[{$i}]"),
            );
            $rendered = Tile::rect($piece['rendered'] ?? null, "manifest.pieces[{$i}].rendered");
            $attributes = ['rendered' => $rendered->toArray(), 'image' => $imageBase . Doc::string($piece, 'filename', "manifest.pieces[{$i}]", 255)];
            if (isset($piece['path'])) {
                $attributes['path'] = Doc::string($piece, 'path', "manifest.pieces[{$i}]", 1 << 20);
            }
            $tiles[] = new Tile(Doc::id($piece['id'] ?? null, "manifest.pieces[{$i}].id"), $i, $rect, $attributes);
            $minSide = min($minSide, $rect->width, $rect->height);
            $maxW = max($maxW, $rendered->width);
            $maxH = max($maxH, $rendered->height);
        }
        if ($tiles === []) {
            throw new InvalidDefinitionException('The manifest has no pieces.');
        }
        $params = $preset === 'custom' ? [] : Presets::get(GameKind::Jigsaw, $preset);
        $ratio = $params['snapRatio'] ?? 0.15;
        $snap ??= max(2, min(JigsawRules::MAX_SNAP, (int) round($minSide * (is_float($ratio) ? $ratio : 0.15))));
        $tray = ['x' => $width + $snap + 1, 'y' => 0, 'width' => max($width, $maxW), 'height' => max($height, $maxH)];
        $rules = [
            'topology' => Doc::string($geometry, 'topology', 'manifest.geometry', 16),
            'snap' => $snap,
            'area' => ['x' => 0, 'y' => 0, 'width' => $tray['x'] + $tray['width'], 'height' => $tray['height']],
            'tray' => $tray,
        ];

        return GameDefinition::create(
            GameKind::Jigsaw,
            $seed,
            $preset,
            Presets::VERSION,
            $width,
            $height,
            $sourceSha256,
            $image,
            Doc::int($manifest, 'rows', 'manifest', 1, 1024),
            Doc::int($manifest, 'cols', 'manifest', 1, 1024),
            new Bounds(0, 0, $width, $height),
            $tiles,
            $rules,
            self::assistance($params['assistance'] ?? self::ASSISTANCE_DEFAULT),
        );
    }

    /**
     * Parameter names the "custom" preset accepts for a mode.
     *
     * @return list<string>
     */
    public static function customKeys(GameKind $kind): array
    {
        return $kind === GameKind::Jigsaw ? DefinitionBuilder::JIGSAW_KEYS : [...self::CUSTOM_KEYS[$kind->value] ?? [], 'assistance'];
    }

    /**
     * @param array<string, mixed> $custom
     * @return array<string, mixed>
     */
    private function parameters(GameKind $kind, string $preset, array $custom): array
    {
        if ($preset === 'custom') {
            if ($custom === []) {
                throw new InvalidArgumentException('The custom preset needs explicit parameters.');
            }
            Doc::keys($custom, self::customKeys($kind), 'parameters');

            return $custom;
        }
        if ($custom !== []) {
            throw new InvalidArgumentException("Parameters are only accepted with the custom preset, not \"{$preset}\".");
        }

        return Presets::get($kind, $preset);
    }

    /**
     * @param array<string, mixed> $params
     * @param array{preview: bool, numbers: bool, highlightCorrect: bool} $assistance
     */
    private function rotation(SourceImage $source, string $image, string $preset, int $seed, array $params, array $assistance): GameDefinition
    {
        $orientations = [];
        foreach (Doc::list($params['orientations'] ?? null, 'orientations', 4) as $i => $angle) {
            $orientations[] = Doc::intValue($angle, "orientations[{$i}]");
        }
        $square = in_array(90, $orientations, true) || in_array(270, $orientations, true);

        return $this->grid(GameKind::Rotation, $source, $image, $preset, $seed, self::side($params, 'rows', 1, 16), self::side($params, 'cols', 1, 16), $assistance, $square, static fn (): array => ['orientations' => $orientations]);
    }

    /**
     * @param array<string, mixed> $params
     * @param array{preview: bool, numbers: bool, highlightCorrect: bool} $assistance
     */
    private function strips(SourceImage $source, string $image, string $preset, int $seed, array $params, array $assistance): GameDefinition
    {
        $count = self::side($params, 'count', 2, 128);
        $axis = Doc::string($params, 'axis', 'parameters', 16);
        [$rows, $cols] = $axis === 'vertical' ? [1, $count] : [$count, 1];

        return $this->grid(GameKind::Strips, $source, $image, $preset, $seed, $rows, $cols, $assistance, false, static fn (): array => ['axis' => $axis], 's');
    }

    /**
     * @param array{preview: bool, numbers: bool, highlightCorrect: bool} $assistance
     * @param \Closure(list<Tile>): array<string, mixed> $rules
     */
    private function grid(GameKind $kind, SourceImage $source, string $image, string $preset, int $seed, int $rows, int $cols, array $assistance, bool $square, \Closure $rules, string $prefix = 't'): GameDefinition
    {
        $cw = intdiv($source->width, $cols);
        $ch = intdiv($source->height, $rows);
        if ($square) {
            $cw = $ch = min($cw, $ch);
        }
        if ($cw < 1 || $ch < 1) {
            throw new InvalidArgumentException("A {$source->width}x{$source->height} image cannot hold a {$rows}x{$cols} board.");
        }
        $crop = new Bounds(intdiv($source->width - $cw * $cols, 2), intdiv($source->height - $ch * $rows, 2), Doc::positiveValue($cw * $cols, 'crop width'), Doc::positiveValue($ch * $rows, 'crop height'));
        $tiles = [];
        for ($i = 0; $i < $rows * $cols; $i++) {
            $tiles[] = new Tile($prefix . $i, $i, new Bounds($crop->x + ($i % $cols) * $cw, $crop->y + intdiv($i, $cols) * $ch, Doc::positiveValue($cw, 'cell width'), Doc::positiveValue($ch, 'cell height')));
        }

        return GameDefinition::create($kind, $seed, $preset, Presets::VERSION, $source->width, $source->height, $source->sha256, $image, $rows, $cols, $crop, $tiles, $rules($tiles), $assistance);
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function side(array $params, string $key, int $min, int $max): int
    {
        return Doc::int($params, $key, 'parameters', $min, $max);
    }

    /**
     * @return array{preview: bool, numbers: bool, highlightCorrect: bool}
     */
    private static function assistance(mixed $value): array
    {
        $assistance = Doc::object($value, 'assistance');
        Doc::keys($assistance, ['preview', 'numbers', 'highlightCorrect'], 'assistance');

        return [
            'preview' => Doc::bool($assistance, 'preview', 'assistance'),
            'numbers' => Doc::bool($assistance, 'numbers', 'assistance'),
            'highlightCorrect' => Doc::bool($assistance, 'highlightCorrect', 'assistance'),
        ];
    }
}
