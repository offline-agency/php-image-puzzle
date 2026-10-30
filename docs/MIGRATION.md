# Upgrading from 1.x to 2.0

2.0 keeps the 1.x entry points (`PuzzleGenerator`, `Puzzle`, `Exporter`,
`ExportOptions`, the edge styles and `EdgeStyleFactory`) but fixes defects
whose old behaviour some code may rely on, and makes the manifest and the
configuration stricter. Game modes, players and the CLI are new and need no
migration. Requirements: PHP 8.4 or 8.5 with ext-gd.

## Checklist

| Area | 1.x | 2.0 | What to do |
| --- | --- | --- | --- |
| Pieces with tabs (`jigsaw`, `arc`, `half-circle` and `sinus` edges) | Piece images were the size of their grid cell; tabs were clipped and neighbours did not interlock | Each image is padded to the piece's **rendered bounds**, and neighbouring pieces share one complementary edge; the outer border is flat | Position a piece image at `pieces[].rendered.x/y` (not `x/y`), and size it with `rendered.width/height` |
| Manifest | Unversioned | `manifestVersion: 2` with `rendered`, `geometry`, `format`, optional `path` outlines and `extensions`; schema [`docs/schemas/v2/manifest.schema.json`](schemas/v2/manifest.schema.json) | Read the new fields; `x/y/width/height` remain the cell rectangle |
| `onManifestBuild` | Could rewrite the whole manifest | May only change the `extensions` object; other changes throw | Move custom data into `extensions` |
| Hooks in `exportParallel()` | Ran in workers | `onPieceBeforeSave` runs in the workers; `onPieceAfterSave` and `onManifestBuild` run in the parent after publication | Do not rely on in-memory side effects of `onPieceBeforeSave` in parallel exports |
| `ExportOptions` quality | One default (`9`) for every format, so JPEG got quality 9 | `null` means the format's default (PNG 9, JPEG 85, WebP 80); values are validated per format (PNG 0-9, JPEG/WebP 0-100) | Pass a quality that fits the format, or none |
| `overwrite: false` | Ignored by `exportParallel()` | Honoured by sequential and parallel export | Expect `ExportFailedException` when targets exist |
| Output paths | Directories were created before checks; symlinks could redirect writes | The output plan is checked first; with a base directory every path must resolve inside it; pieces are staged and published atomically | Pass a base directory when paths come from users ([OPERATIONS.md](OPERATIONS.md#paths-and-trust)) |
| Edge style configuration | Unknown keys and loose types were coerced | Strict: unknown keys, non-integer radii and options on styles without options are rejected with the offending path. 1.x configuration files migrate through `PuzzleConfig` | See below |
| `CompositeEdgeStyle` | Copied one-pixel strips from each side style | Composes whole side profiles and requires complementary opposite sides | Give opposite sides compatible styles |
| `RoundedCornersStyle` | Erased the square corners and ignored the documented radius | Rounds each corner with the configured radius (clamped to half the shorter side) | Expect different corner pixels |
| `SinusStyle` | Tabs and sockets were identical and the outer border changed | Complementary tabs and sockets, flat border | Expect different piece outlines |
| Invalid grids and indices | Zero or negative sizes and out-of-range indices were accepted | Rejected with `InvalidArgumentException` before any image work | Validate input or catch the exception |
| Large inputs | No limits | `ResourceLimits` rejects files, dimensions, pixel counts and piece counts above the defaults before decoding | Pass your own `ResourceLimits` if you need more ([OPERATIONS.md](OPERATIONS.md#limits)) |
| `ImageLoader` | Needed ext-exif for type detection | Uses `getimagesize()`; EXIF orientation is optional (`applyExifOrientation`); animated GIFs are rejected unless `allowAnimatedFirstFrame` | Opt in where you relied on either |
| `ClassicEdgeHelper` | Public class | Removed | Use the edge style classes |
| `Piece` constructor | Took the cell rectangle | Takes a `PieceShape` | Create pieces through `PuzzleGenerator` |

## Configuration files

`PuzzleConfig` reads 1.x configuration arrays (no `configVersion`) and 2.0
ones (`configVersion: 2`). A 1.x file is migrated, and every lossy step is
reported in `warnings()` instead of being dropped silently:

<!-- snippet -->
```php
use ImagePuzzle\Config\PuzzleConfig;

$config = PuzzleConfig::fromArray([
    'input' => 'photo.jpg',
    'rows' => 4,
    'cols' => 6,
    'edge' => ['type' => 'jigsaw'],
    'output' => ['directory' => 'output/puzzle', 'format' => 'png'],
]);

foreach ($config->warnings() as $warning) {
    echo "warning: {$warning}\n";
}
file_put_contents('puzzle-config.json', $config->toJson()); // a version 2 document
```

Validate edited files offline with `composer schemas` in this repository, or
against [`docs/schemas/v2/puzzle-config.schema.json`](schemas/v2/puzzle-config.schema.json).

## Placing pieces from a version 2 manifest

<!-- snippet -->
```php
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\EdgeStyle\JigsawStyle;

$result = PuzzleGenerator::generateAndExport('photo.jpg', 'pieces', 3, 4, new JigsawStyle());
foreach ($result->getManifest()['pieces'] as $piece) {
    // The image file covers the rendered box, which includes the tabs.
    printf("%s at %d,%d (%dx%d)\n", $piece['filename'], $piece['rendered']['x'], $piece['rendered']['y'], $piece['rendered']['width'], $piece['rendered']['height']);
}
```

Drawing every piece at its rendered position reproduces the source: the
antialiased coverages of two neighbours sum to one along their shared edge
when composited additively (`geometry.compositing`), and `image-puzzle
reconstruct` draws the board from a built puzzle.
