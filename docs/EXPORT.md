# Export formats and options

The package writes three kinds of output:

1. **Pieces and a manifest** (`PuzzleGenerator`, `Exporter`): one image per
   piece plus `manifest.json` (manifest v2). See [Pieces and manifest](#pieces-and-manifest).
2. **A playable puzzle directory** (`DefinitionBuilder`): `definition.json`
   (game definition v1), the source image and, for jigsaws, the piece images,
   optionally with smaller sets and an atlas. See [Piece images for the player](#piece-images-for-the-player).
3. **Derived exports of a puzzle directory**: SVG, printable PDF, offline ZIP
   bundle and a reconstruction of the solved board. See [SVG](#svg), [PDF](#pdf),
   [ZIP bundle](#zip-bundle) and [Reconstruction](#reconstruction).

Everything is also available from the command line: see [CLI.md](CLI.md).

## Image formats

| Format | Transparency | Quality | Default |
| --- | --- | --- | --- |
| PNG (`ExportOptions::FORMAT_PNG`) | yes | zlib level 0–9 | 9 |
| JPEG (`ExportOptions::FORMAT_JPEG`) | no: flattened onto `jpegBackground` (white) | 0–100 | 85 |
| WebP (`ExportOptions::FORMAT_WEBP`) | yes | 0–100 | 80 |

Encoders depend on how GD was built. `ImagePuzzle\Export\ImageEncoder::capabilities()`
returns `['png' => bool, 'jpeg' => bool, 'webp' => bool]`, and
`image-puzzle capabilities` prints the same information. Requesting a format
GD cannot encode throws `CapabilityUnavailableException` (for example "This
GD build cannot encode webp images.") before anything is written; the CLI
exits with code 4.

Input formats are PNG, JPEG, GIF and WebP, as far as GD can decode them
(`ImageLoader::decoders()`).

## Pieces and manifest

```php
use ImagePuzzle\ExportOptions;

$options = new ExportOptions(format: ExportOptions::FORMAT_WEBP, quality: 82);

// Fluent API: each with*() returns a new immutable copy.
$options = (new ExportOptions())
    ->withFormat(ExportOptions::FORMAT_JPEG)
    ->withQuality(85);
```

| Parameter | Default | Description |
| --- | --- | --- |
| `format` | `'png'` | `png`, `jpeg` or `webp` |
| `quality` | `null` | `null` selects the format default above; an explicit value must be valid for the format |
| `filenamePattern` | `'piece_{row}_{col}.png'` | Placeholders `{row}`, `{col}`, `{index}`, `{rows}`, `{cols}`. A `.png` extension is replaced by the format's extension. No path separators. |
| `overwrite` | `true` | `false` refuses to replace existing piece files |
| `manifestFilename` | `'manifest.json'` | Must end with `.json`; no path separators |
| `onPieceBeforeSave`, `onPieceAfterSave`, `onManifestBuild` | `null` | Hooks, see below |
| `jpegBackground` | `[255, 255, 255]` | RGB used to flatten transparency for JPEG |
| `publishMode` | `'in_place'` | `in_place` writes into the directory; `generation` writes every export to a new `generations/<timestamp>-<id>/` directory |
| `includePaths` | `true` | Include each piece's SVG outline (`path`) in the manifest |

Fluent methods: `withFormat()`, `withQuality()`, `withFilenamePattern()`,
`withOverwrite()`, `withManifestFilename()`, `withOnPieceBeforeSave()`,
`withOnPieceAfterSave()`, `withOnManifestBuild()`, `withJpegBackground()`,
`withPublishMode()`, `withIncludePaths()`.

### Hooks

| Callback | Signature | When |
| --- | --- | --- |
| `onPieceBeforeSave` | `(Piece $piece, string $filepath): void` | Before a piece is encoded; the file is not published yet |
| `onPieceAfterSave` | `(Piece $piece, string $filepath): void` | After the piece is published at its final path |
| `onManifestBuild` | `(array $manifest): array` | Before the manifest is written; only its `extensions` object may change |

In parallel export, piece hooks run in the workers and `onManifestBuild` runs
once in the parent process.

### Base directory

When paths come from users, pass an allowed base directory (fourth argument
of `Exporter::export()`, last argument of `Exporter::exportParallel()`, third
argument of `Puzzle::export()`). The output directory must resolve inside it,
otherwise `ExportFailedException` is thrown.

```php
$result = $puzzle->export('/var/www/exports/user-123/puzzle', $options, '/var/www/exports');
```

You can also pass your own `Exporter` to `PuzzleGenerator` (or `Puzzle`) to
share or wrap it.

### Output

`ExportResult` gives `getOutputDirectory()`, `getFiles()` (pieces and
manifest), `getManifestPath()` and `getManifest()`. The manifest follows
[docs/schemas/v2/manifest.schema.json](schemas/v2/manifest.schema.json):
`manifestVersion`, `source`, `rows`, `cols`, `sourceWidth`, `sourceHeight`,
`format`, `geometry`, `pieces` (cell rectangle, rendered rectangle, file and,
with `includePaths`, the outline) and `extensions`.

## Piece images for the player

`DefinitionBuilder::build()` and `buildConfigured()` take an `AssetOptions`
that controls the images the browser player loads:

```php
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;

$assets = new AssetOptions(
    format: 'webp',                         // png (default), jpeg or webp
    quality: 80,                            // null: format default
    resolutions: ['thumbnail', 'mobile'],   // or ['small' => 480]
    atlas: 'mobile',                        // 'full', a resolution name, or null
    atlasPadding: 2,                        // 0-16 px between frames
    atlasMaxSize: 8192,                     // 64-16384 px per side
);
(new DefinitionBuilder())->build('photo.jpg', 'public/puzzles/42', GameKind::Jigsaw, 'medium', [], 7, $assets);
```

- `format`/`quality` apply to every generated image. Grid modes (sliding,
  swap, rotation, strips) re-encode their single board image; resolutions
  and atlases are jigsaw-only (`InvalidArgumentException` otherwise).
- `resolutions` adds smaller copies of every piece. Built-in names are
  `thumbnail` (320), `mobile` (1080) and `desktop` (1920) px for the whole
  source width; custom sets are `name => width` (16–65536). At most four. A
  set at least as wide as the source is skipped, because the full pieces
  already serve it. Each set is written to `pieces-<name>/` with the same
  file names as `pieces/`.
- `atlas` packs the full pieces (`full`) or one resolution into a single
  `atlas.<ext>` with deterministic shelf packing. Frames keep `atlasPadding`
  px apart and their edge pixels are extruded into the padding, so sampling
  at fractional scales does not pull in a neighbour. An atlas that does not
  fit `atlasMaxSize` throws `ResourceLimitExceededException`: pack a smaller
  resolution or raise the limit.

The definition records them under `media.resolutions` (`name`, `width`,
`directory`) and `media.atlas` (`image`, `width`, `height`, `frames` keyed by
tile ID, in tile order). Tile IDs, outlines and rectangles do not change:
every set shares the source pixel coordinate system, so hit-testing and
snapping are identical whichever images are shown.

The player picks images as follows (see [PLAYER_API.md](PLAYER_API.md)):

- With an atlas, it loads the atlas once and draws each frame into a canvas
  per piece.
- Otherwise each piece is an `<img>` with a `srcset` of the full pieces and
  every set and a `sizes` value measured from the board, with a 1.5× density
  margin so that the browser does not pick a set that would be upscaled.

### Choosing piece images

Measured with `php tools/measure-assets.php` (synthetic 1920×1440 picture,
4×6 jigsaw, PHP 8.5.12, GD 2.0.35, 2026-10-25). "Decoded memory" is
4 bytes per pixel of the images the player decodes for one board, plus the
per-piece canvases for atlas builds; it is computed from image sizes, not
read from a browser. A real photo compresses differently, so run the script
on your own pictures (`php tools/measure-assets.php <width> <rows> <cols>`
uses the same synthetic generator at another size).

| Build | Image requests | Image bytes | Decoded memory |
| --- | ---: | ---: | ---: |
| PNG pieces (default) | 24 | 1,573 KiB | 14.8 MiB |
| WebP pieces | 24 | 386 KiB | 14.8 MiB |
| PNG pieces, mobile set (1080) | 24 | 962 KiB | 4.7 MiB |
| WebP pieces, thumbnail set (320) | 24 | 27 KiB | 0.4 MiB |
| PNG atlas (full) | 1 | 1,654 KiB | 34.2 MiB |
| WebP atlas (full) | 1 | 363 KiB | 34.2 MiB |
| WebP atlas (mobile set) | 1 | 94 KiB | 10.9 MiB |

What this shows, and what it does not:

- WebP cut the transferred bytes by about 75% here; decoded memory is the
  same as PNG, since both decode to RGBA.
- Smaller sets cut both bytes and memory, and only matter on small or
  low-density screens; the full pieces are still used where they are needed.
- An atlas turns 24 requests into one, but it decodes the atlas **and** a
  canvas per piece, so it more than doubles decoded memory. It helps when
  request count or latency dominates (HTTP/1.1, many pieces, slow
  round-trips), not on HTTP/2 with few pieces. It is not faster in general.

Seams between solved pieces were measured in the browser suite
(`packages/player/e2e/assets.spec.ts`) by rendering the solved board over
black and over white and summing the difference along the seams. The test
requires resolution sets to stay within 1.15× (+10) of the full PNG pieces
and atlases within 1.25× (+10), in Chromium, Firefox, WebKit and the two
mobile emulations, at 300 and 700 px board widths. On 2026-10-25 the largest
ratio was 1.21×, for the atlas in desktop WebKit at 300 px.

## SVG

`ImagePuzzle\Export\SvgExporter` draws a built puzzle from its definition.
SVG and PDF need pieces cut from one sheet. They refuse a nonogram, whose
picture is its solution, and memory, whose cards come in pairs that one sheet
cannot hold, with `InvalidArgumentException` (CLI exit code 3). A tangram
prints its seven pieces cut along their solved outlines. Bundles and
reconstruction work for every mode.

```php
use ImagePuzzle\Export\SvgExporter;
use ImagePuzzle\Export\SvgOptions;
use ImagePuzzle\Game\PuzzleDirectory;

$puzzle = PuzzleDirectory::open('public/puzzles/42');
(new SvgExporter())->write($puzzle, 'puzzle.svg', new SvgOptions(image: SvgOptions::IMAGE_EMBED, widthMm: 200.0));
```

- Coordinates are source pixels; the `viewBox` is the board crop. Each piece
  is the source image clipped by its outline (`<clipPath>` per tile, `<use>`
  per piece), and `<g id="outlines">` holds one `<path>` per tile. These are
  the same outlines the rasterizer cut and the player hit-tests (grid modes
  use the tile rectangles).
- `image`: `link` (default) references the definition's relative image path,
  so keep the SVG next to the puzzle files; `embed` inlines it as a data URI
  (up to 32 MiB, `ResourceLimitExceededException` beyond); `none` draws
  outlines only, the only choice for a definition whose image is an `https`
  URL.
- `pieces`, `outlines`, `stroke` (`#rrggbb`), `strokeWidth`, `widthMm`
  (physical size), `title`, `description`, `precision` (decimals).
- The SVG is generated, never copied: no scripts, event handlers, styles,
  foreign objects or links other than the image. Title and description are
  escaped, stripped of characters XML forbids and limited to 1000 characters.
- `write()` refuses to replace an existing file.

## PDF

`ImagePuzzle\Export\PdfExporter` writes a printable PDF 1.4 without extra
dependencies (the picture is one JPEG; content streams use zlib when
available):

```php
use ImagePuzzle\Export\PdfExporter;
use ImagePuzzle\Export\PdfOptions;

(new PdfExporter())->write($puzzle, 'puzzle.pdf', new PdfOptions(paper: 'a3', widthMm: 500.0));
```

- `paper`: `a4` or `a3`; `orientation`: `auto` (the orientation that prints
  the board larger, or on fewer sheets), `portrait` or `landscape`.
- `widthMm`: printed board width. By default the board fits one sheet inside
  the margins. A wider board is tiled over several sheets: each sheet prints
  the next part of the printable area, with crop marks at the corners for
  trimming and joining, and a footer naming the sheet and its row and column.
- `marginMm` (5–50, default 10), `cutLines` with `lineWidthPt` and
  `lineColor`, `maxDpi` (the image is downsampled to this resolution at the
  printed size, never upsampled), `jpegQuality`, `title`.
- `reference`: a last page with the whole solved board, the outlines and the
  piece numbers, scaled to one sheet.
- Print at 100% ("actual size"), not "fit to page", to keep the physical
  size. Text uses the standard Helvetica font, so non-ASCII characters in
  the title are printed as "?". There is no bleed.
- `layout()` returns pages, sheets, paper and board size without rendering.
- The same input always produces the same bytes (no dates are written).

## ZIP bundle

`ImagePuzzle\Export\BundleExporter` packages a built puzzle so it can be
played offline from any folder. It needs ext-zip (`BundleExporter::supported()`);
without it `write()` throws `CapabilityUnavailableException`.

```php
use ImagePuzzle\Export\BundleExporter;

(new BundleExporter())->write($puzzle, 'puzzle.zip', 'my-puzzle', 'Our team photo');
```

Contents, all under one folder (`image-puzzle/` by default):

| Entry | Content |
| --- | --- |
| `index.html` | The player page, with the definition inline |
| `player/image-puzzle-player.iife.js` | The player script, copied from this package's `resources/dist/` |
| `definition.json` | The definition, byte for byte |
| source image, `pieces/`, sets, atlas | Every file the definition references |
| `preview.png` | The solved board, at most 640 px wide |
| `README.txt`, `NOTICE.txt`, `LICENSE.txt` | How to open it, provenance, the MIT license of the player |
| `bundle.json` | `format`, `version`, `definition`, `entry` and every other entry with its size and SHA-256 |

- **Opening it.** Extract the whole archive, then open `index.html`. Opening
  it directly (`file://`) works: the definition is inline and the player is
  a classic script, because browsers refuse to `fetch()` JSON and to load
  ES modules from `file://` pages. Serving the folder over HTTP works too
  (`php -S 127.0.0.1:8000` or `python3 -m http.server` in the folder). No
  CDN or other network access is used.
- **Safety and reproducibility.** Every entry is a relative path checked
  inside the puzzle directory; entries are unique, sorted, dated 1980-01-02
  and have 0644 permissions, so the same puzzle produces the same archive.
  The archive is written to a temporary name and renamed; an existing target
  is an error. `maxBytes` (default 512 MiB) bounds the content.
- **Licensing.** The player script is MIT-licensed. The picture and piece
  images are derived from the source image and keep its owner's rights,
  which `NOTICE.txt` states with the source SHA-256.
- `entries()` returns the contents without writing, for custom packaging.

The extracted bundle is tested in Chromium, Firefox, WebKit and two mobile
emulations, over HTTP and from `file://` (`packages/player/e2e/bundle.spec.ts`):
it must load only its own files, render every piece and be solvable.

## Reconstruction

`ImagePuzzle\Export\Reconstruction` draws every piece at its solved position
(grid modes: the board crop) and returns a `GdImage` or writes a PNG. For a
correct build the result matches the source crop up to the antialiased seams
between jigsaw pieces, so it is both a preview and a check of the build.

## How the exports are verified

- `tests/Export/VectorExportTest.php` renders the SVG with `rsvg-convert`
  and compares it with the source, checks every outline against the alpha
  mask of its raster piece, checks PDF page sizes with `pdfinfo` and
  `qpdf --check`, and renders pages with `pdftoppm` to check the board's
  physical size and position, the cut lines on the outlines, the
  continuation across tiled sheets and the reference page text. CI installs
  the tools and sets `REQUIRE_RENDERERS=1`, so they cannot be skipped there.
- `tests/Export/BundleExporterTest.php` checks entries, checksums,
  determinism, permissions, limits and the missing-extension path (really
  absent in the CI job without ext-zip).
- `tools/consumer/run.sh` installs the dist archive in a fresh project and
  drives the installed CLI, including malformed input, unsafe paths and
  limits.
