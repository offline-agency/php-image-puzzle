# Export formats and options

## Formats

- **PNG** (default) – Supports transparency. Compression level 0–9 (9 = maximum). Use for pieces that may have alpha (e.g. with rounded corners).
- **JPEG** – No transparency. Quality 0–100. Use for opaque photos when smaller file size is desired.

Set the format via `ExportOptions` (constructor or fluent API):

```php
use ImagePuzzle\ExportOptions;

$options = new ExportOptions(format: ExportOptions::FORMAT_PNG);
$options = new ExportOptions(format: ExportOptions::FORMAT_JPEG, quality: 85);

// Fluent API: chain with*() methods for a new immutable copy
$options = (new ExportOptions())
    ->withFormat(ExportOptions::FORMAT_JPEG)
    ->withQuality(85);
```

## ExportOptions

| Parameter          | Default           | Description |
|--------------------|-------------------|-------------|
| `format`           | `'png'`           | `ExportOptions::FORMAT_PNG` or `ExportOptions::FORMAT_JPEG` |
| `quality`          | `9`               | PNG: compression 0–9. JPEG: quality 0–100 |
| `filenamePattern`  | `'piece_{row}_{col}.png'` | Placeholders: `{row}`, `{col}`. With grid context (used by Exporter): `{index}` (row×cols+col), `{rows}`, `{cols}`. When format is JPEG, the extension is auto-corrected to `.jpg` only if the pattern ends with `.png`; otherwise the pattern is used as-is. |
| `overwrite`        | `true`            | If `false`, existing piece files are not overwritten (manifest still written). |
| `manifestFilename` | `'manifest.json'` | Name of the manifest file in the output directory. |

Fluent methods (return a new instance): `withFormat()`, `withQuality()`, `withFilenamePattern()`, `withOverwrite()`, `withManifestFilename()`, `withOnPieceBeforeSave()`, `withOnPieceAfterSave()`, `withOnManifestBuild()`.

### Export hooks (callbacks)

Optional callbacks let you observe or customize the export process:

| Callback | Signature | When invoked |
|----------|-----------|--------------|
| `onPieceBeforeSave` | `(Piece $piece, string $filepath): void` | Before each piece image is written to disk. |
| `onPieceAfterSave` | `(Piece $piece, string $filepath): void` | After each piece image is written. |
| `onManifestBuild` | `(array $manifest): array` | After the default manifest array is built; return the (possibly modified) array that will be JSON-encoded and written. Use this to add custom keys (global or per-piece) or change the manifest structure. |

Pass them in the constructor or use the fluent methods `withOnPieceBeforeSave()`, `withOnPieceAfterSave()`, `withOnManifestBuild()`. In parallel export, piece callbacks run in workers; `onManifestBuild` runs once in the main process after workers finish.

## Base directory validation

When paths are user-supplied, you can have the library enforce that the output directory is inside an allowed base directory. Pass the base directory as the fourth argument to `Exporter::export()`, the last argument to `Exporter::exportParallel()`, or the third argument to `Puzzle::export()`. The output directory (after creation) must resolve to a path under the base directory; otherwise `ExportFailedException` is thrown.

```php
$baseDir = '/var/www/allowed-exports';
$result = $puzzle->export('/var/www/allowed-exports/user-123/puzzle', $options, $baseDir);
```

## Injectable Exporter

You can pass an optional `Exporter` instance when constructing a `PuzzleGenerator`. The generated `Puzzle` will then use that exporter when you call `$puzzle->export(...)`. Use this to share a single exporter instance or to plug in custom export behavior via composition (e.g. a wrapper around `Exporter`). The `Puzzle` class also accepts an optional `Exporter` in its constructor (used when building a puzzle with a generator that was given an exporter).

## Output

- Each piece is written as a separate file.
- The manifest is a JSON file with `source`, `rows`, `cols`, `sourceWidth`, `sourceHeight`, and a `pieces` array. Each entry in `pieces` has `row`, `col`, `x`, `y`, `width`, `height`, `filename`.

## ExportResult

After calling `$puzzle->export($directory, $options)` you get an `ExportResult` with:

- `getOutputDirectory()` – absolute or relative path to the output directory
- `getFiles()` – list of all written file paths (pieces + manifest)
- `getManifestPath()` – path to the manifest JSON
- `getManifest()` – decoded manifest as an associative array
