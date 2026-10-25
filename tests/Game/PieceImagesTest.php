<?php

declare(strict_types=1);

use ImagePuzzle\Exception\CapabilityUnavailableException;
use ImagePuzzle\Exception\InvalidArgumentException;
use ImagePuzzle\Exception\InvalidDefinitionException;
use ImagePuzzle\Exception\ResourceLimitExceededException;
use ImagePuzzle\Export\AtlasPacker;
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameDefinition;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\PieceAtlas;
use ImagePuzzle\Game\PieceImages;
use ImagePuzzle\Game\PieceResolution;
use ImagePuzzle\Game\Tile;
use ImagePuzzle\Geometry\Bounds;
use ImagePuzzle\Tests\Support\Fixtures;

/*
 * Alternative jigsaw piece images (F: WebP, resolutions, atlases): smaller
 * sets and spritesheets keep tile IDs and geometry, and every pixel comes
 * from the full-resolution piece image.
 */

final class PieceImageWorkspaces
{
    /** @var list<string> */
    public static array $dirs = [];
}

/**
 * @return array{0: string, 1: string} directory and a 320x240 source path
 */
function pieceImagesWorkspace(): array
{
    $dir = Fixtures::tempDir('ip-pieces-');
    PieceImageWorkspaces::$dirs[] = $dir;
    imagepng(Fixtures::coordinateImage(320, 240, false), $dir . '/photo.png');

    return [$dir, $dir . '/photo.png'];
}

function tileImage(Tile $tile): string
{
    return ImagePuzzle\Game\Doc::stringValue($tile->attributes['image'] ?? null, "tile {$tile->id} image");
}

function buildJigsaw(string $source, string $out, AssetOptions $assets = new AssetOptions()): ImagePuzzle\Game\BuildResult
{
    return (new DefinitionBuilder())->build($source, $out, GameKind::Jigsaw, 'custom', ['rows' => 3, 'cols' => 4], 11, $assets);
}

afterEach(function (): void {
    foreach (PieceImageWorkspaces::$dirs as $dir) {
        Fixtures::removeDir($dir);
    }
    PieceImageWorkspaces::$dirs = [];
});

describe('AtlasPacker', function (): void {
    it('packs deterministically, in input order, with padding between frames', function (): void {
        $sizes = ['a' => [30, 20], 'b' => [10, 40], 'c' => [25, 25], 'd' => [8, 8], 'e' => [30, 20]];
        $first = AtlasPacker::pack($sizes, 2, 1024);
        expect(AtlasPacker::pack($sizes, 2, 1024))->toEqual($first)
            ->and(array_keys($first['frames']))->toBe(['a', 'b', 'c', 'd', 'e'])
            ->and($first['width'] % 4)->toBe(0);
        foreach ($first['frames'] as $id => $frame) {
            expect([$frame->width, $frame->height])->toBe($sizes[$id])
                ->and($frame->x)->toBeGreaterThanOrEqual(2)
                ->and($frame->y)->toBeGreaterThanOrEqual(2)
                ->and($frame->x + $frame->width + 2)->toBeLessThanOrEqual($first['width'])
                ->and($frame->y + $frame->height + 2)->toBeLessThanOrEqual($first['height']);
            foreach ($first['frames'] as $other => $box) {
                if ($other === $id) {
                    continue;
                }
                // Grown by the padding, two frames still do not touch.
                $apart = $frame->x + $frame->width + 2 <= $box->x || $box->x + $box->width + 2 <= $frame->x
                    || $frame->y + $frame->height + 2 <= $box->y || $box->y + $box->height + 2 <= $frame->y;
                expect($apart)->toBeTrue("{$id} and {$other} overlap");
            }
        }
    });

    it('refuses an atlas over the size limit with an actionable message', function (): void {
        AtlasPacker::pack(['a' => [500, 500], 'b' => [500, 500]], 2, 512);
    })->throws(ResourceLimitExceededException::class, 'pack a smaller resolution');
});

describe('AssetOptions', function (): void {
    it('expands named resolutions and orders them by width', function (): void {
        $options = new AssetOptions(resolutions: ['mobile', 'thumbnail', 'custom-1' => 500]);
        expect($options->resolutions)->toBe(['thumbnail' => 320, 'custom-1' => 500, 'mobile' => 1080]);
    });

    it('rejects invalid requests', function (Closure $create, string $message): void {
        expect($create)->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'unknown name' => [static fn () => new AssetOptions(resolutions: ['retina']), 'Unknown resolution "retina"'],
        'tiny width' => [static fn () => new AssetOptions(resolutions: ['x' => 8]), 'between 16 and 65536'],
        'bad name' => [static fn () => new AssetOptions(resolutions: ['Big' => 100]), 'must match'],
        'same width' => [static fn () => new AssetOptions(resolutions: ['a' => 100, 'b' => 100]), 'same width'],
        'too many' => [static fn () => new AssetOptions(resolutions: ['a' => 100, 'b' => 200, 'c' => 300, 'd' => 400, 'e' => 500]), 'At most 4'],
        'atlas set not requested' => [static fn () => new AssetOptions(atlas: 'mobile'), 'neither "full"'],
        'padding' => [static fn () => new AssetOptions(atlas: 'full', atlasPadding: 40), 'padding'],
        'format' => [static fn () => new AssetOptions('gif'), 'Unsupported export format'],
        'quality' => [static fn () => new AssetOptions('webp', 101), 'Quality 101'],
    ]);
});

describe('DefinitionBuilder alternative piece images', function (): void {
    it('writes smaller sets with the same IDs, file names and geometry', function (): void {
        [$dir, $source] = pieceImagesWorkspace();
        $plain = buildJigsaw($source, $dir . '/plain');
        $result = buildJigsaw($source, $dir . '/sets', new AssetOptions(resolutions: ['small' => 80, 'medium' => 160, 'huge' => 4000]));
        $definition = $result->definition;

        // The 4000 px set is not narrower than the source: skipped.
        expect(array_map(static fn (PieceResolution $r): array => $r->toArray(), $definition->pieceImages->resolutions))->toBe([
            ['name' => 'small', 'width' => 80, 'directory' => 'pieces-small'],
            ['name' => 'medium', 'width' => 160, 'directory' => 'pieces-medium'],
        ])
            ->and(array_map(static fn (Tile $t): array => $t->toArray(), $definition->tiles))
            ->toBe(array_map(static fn (Tile $t): array => $t->toArray(), $plain->definition->tiles))
            ->and($definition->initial)->toBe($plain->definition->initial)
            ->and($definition->id)->not->toBe($plain->definition->id);

        foreach ($definition->tiles as $tile) {
            $rendered = Tile::rect($tile->attributes['rendered'], 'rendered');
            foreach ($definition->pieceImages->resolutions as $resolution) {
                $path = $dir . '/sets/' . $resolution->directory . '/' . basename(tileImage($tile));
                expect(Fixtures::size($path))->toBe([
                    $resolution->scaled($rendered->width, 320),
                    $resolution->scaled($rendered->height, 320),
                ]);
            }
        }
        expect(GameDefinition::fromJson((string) file_get_contents($result->definitionPath()))->toArray())->toBe($definition->toArray());
    });

    it('resamples alpha: a downscaled piece covers its outline like the full one', function (): void {
        [$dir, $source] = pieceImagesWorkspace();
        $result = buildJigsaw($source, $dir . '/sets', new AssetOptions(resolutions: ['half' => 160]));
        foreach ($result->definition->tiles as $tile) {
            $full = Fixtures::load($dir . '/sets/' . tileImage($tile));
            $half = Fixtures::load($dir . '/sets/pieces-half/' . basename(tileImage($tile)));
            $coverage = static function (GdImage $image): float {
                $sum = 0;
                for ($y = 0; $y < imagesy($image); $y++) {
                    for ($x = 0; $x < imagesx($image); $x++) {
                        $sum += 127 - Fixtures::alpha($image, $x, $y);
                    }
                }

                return $sum / 127;
            };
            // Area scales with the square of the scale factor (2 % tolerance).
            expect($coverage($half) * 4)->toEqualWithDelta($coverage($full), $coverage($full) * 0.02);
        }
    });

    it('packs the full pieces into an atlas whose frames hold their exact pixels', function (): void {
        [$dir, $source] = pieceImagesWorkspace();
        $result = buildJigsaw($source, $dir . '/atlas', new AssetOptions(atlas: AssetOptions::ATLAS_FULL, atlasPadding: 4));
        $atlas = $result->definition->pieceImages->atlas;
        expect($atlas)->toBeInstanceOf(PieceAtlas::class)
            ->and($result->files)->toContain('atlas.png');
        assert($atlas instanceof PieceAtlas);
        $sheet = Fixtures::load($dir . '/atlas/atlas.png');
        expect([imagesx($sheet), imagesy($sheet)])->toBe([$atlas->width, $atlas->height]);
        foreach ($result->definition->tiles as $tile) {
            $piece = Fixtures::load($dir . '/atlas/' . tileImage($tile));
            $frame = $atlas->frames[$tile->id];
            expect([$frame->width, $frame->height])->toBe([imagesx($piece), imagesy($piece)]);
            for ($y = 0; $y < $frame->height; $y++) {
                for ($x = 0; $x < $frame->width; $x++) {
                    if (Fixtures::rgba($piece, $x, $y) !== Fixtures::rgba($sheet, $frame->x + $x, $frame->y + $y)) {
                        throw new RuntimeException("Frame {$tile->id} differs at {$x},{$y}.");
                    }
                }
            }
            // Extrusion: the first column is repeated twice to the left (padding 4 / 2).
            for ($y = 0; $y < $frame->height; $y++) {
                expect(Fixtures::rgba($sheet, $frame->x - 2, $frame->y + $y))->toBe(Fixtures::rgba($piece, 0, $y));
            }
        }
    });

    it('packs a smaller set when asked, and reads back as a valid definition', function (): void {
        [$dir, $source] = pieceImagesWorkspace();
        $result = buildJigsaw($source, $dir . '/both', new AssetOptions('webp', 70, ['small' => 80], 'small'));
        $atlas = $result->definition->pieceImages->atlas;
        assert($atlas instanceof PieceAtlas);
        expect($atlas->image)->toBe('atlas.webp')
            ->and(getimagesize($dir . '/both/atlas.webp')[2] ?? null)->toBe(IMAGETYPE_WEBP)
            ->and(getimagesize($dir . '/both/' . tileImage($result->definition->tiles[0]))[2] ?? null)->toBe(IMAGETYPE_WEBP);
        foreach ($result->definition->tiles as $tile) {
            $small = Fixtures::size($dir . '/both/pieces-small/' . basename(tileImage($tile)));
            expect([$atlas->frames[$tile->id]->width, $atlas->frames[$tile->id]->height])->toBe($small);
        }
        expect(GameDefinition::fromJson((string) file_get_contents($result->definitionPath()))->id)->toBe($result->definition->id);
    });

    it('re-encodes the board image of grid modes and rejects piece sets for them', function (): void {
        [$dir, $source] = pieceImagesWorkspace();
        $result = (new DefinitionBuilder())->build($source, $dir . '/swap', GameKind::Swap, 'easy', [], 1, new AssetOptions('webp'));
        expect($result->definition->image)->toBe('board.webp')
            ->and($result->files)->toBe(['board.webp', 'definition.json'])
            ->and(getimagesize($dir . '/swap/board.webp')[2] ?? null)->toBe(IMAGETYPE_WEBP);

        expect(static fn () => (new DefinitionBuilder())->build($source, $dir . '/rot', GameKind::Rotation, 'easy', [], 1, new AssetOptions(atlas: 'full')))
            ->toThrow(InvalidArgumentException::class, 'only available for jigsaw')
            ->and(is_dir($dir . '/rot'))->toBeFalse();
    });
});

describe('definition.media validation', function (): void {
    it('rejects alternatives that do not match the definition', function (PieceImages $images, string $message): void {
        [$dir, $source] = pieceImagesWorkspace();
        $definition = buildJigsaw($source, $dir . '/plain')->definition;
        expect(static fn () => $definition->withPieceImages($images))->toThrow(InvalidDefinitionException::class, $message);
    })->with([
        'as wide as the source' => [new PieceImages([new PieceResolution('big', 320, 'pieces-big')]), 'must be narrower'],
        'tile image directory' => [new PieceImages([new PieceResolution('small', 80, 'pieces')]), 'directory of the full-resolution'],
        'missing frames' => [new PieceImages([], new PieceAtlas('atlas.png', 10, 10, ['r0c0' => new Bounds(0, 0, 5, 5)])), 'every tile ID once'],
    ]);
});

it('reports a missing WebP encoder as a capability error before writing anything', function (): void {
    [$dir, $source] = pieceImagesWorkspace();
    $script = $dir . '/build.php';
    file_put_contents($script, sprintf(
        '<?php require %s; try { (new ImagePuzzle\Game\DefinitionBuilder())->build(%s, %s, ImagePuzzle\Game\GameKind::Jigsaw, "easy", [], 1, new ImagePuzzle\Game\AssetOptions("webp")); echo "built"; } catch (%s $e) { echo get_class($e), ": ", $e->getMessage(); }',
        var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
        var_export($source, true),
        var_export($dir . '/out', true),
        CapabilityUnavailableException::class,
    ));
    $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=imagewebp ' . escapeshellarg($script) . ' 2>&1');
    expect($output)->toBe(CapabilityUnavailableException::class . ': This GD build cannot encode webp images.')
        ->and(file_exists($dir . '/out'))->toBeFalse();
});
