# Usage

This guide walks through loading an image, splitting it into a grid, and exporting pieces with a manifest.

## One-shot (simplest)

For the common case “file → puzzle → export” in one call:

```php
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;
use ImagePuzzle\ExportOptions;

$result = PuzzleGenerator::generateAndExport(
    '/path/to/image.jpg',
    '/path/to/output_dir',
    4,
    6,
    new RoundedCornersStyle(4),
    new ExportOptions()
);
echo $result->getManifestPath();
```

## Step 1: Create a generator

Use the default (no edge style) or pass an edge style:

```php
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;

$generator = new PuzzleGenerator();
// or with rounded corners:
$generator = new PuzzleGenerator(new RoundedCornersStyle(4));
// optionally pass an Exporter instance so the generated puzzle uses it on export():
$generator = new PuzzleGenerator(null, null, new \ImagePuzzle\Exporter());
// optionally pass a DimensionSplitStrategy to customize how the grid is divided (see docs/SPLIT_STRATEGY.md):
$generator = new PuzzleGenerator(null, null, null, new \ImagePuzzle\SplitStrategy\DefaultSplitStrategy());
```

## Step 2: Load and split the image

From a file path:

```php
$puzzle = $generator->generateFromFile('/path/to/image.jpg', $rows, $cols);
```

Or from an existing `GdImage`:

```php
$image = imagecreatefromjpeg('/path/to/image.jpg');
$puzzle = $generator->generateFromGd($image, $rows, $cols);
```

`$rows` and `$cols` must be at least 1. Supported image types: PNG, JPEG, GIF.

## Step 3: Inspect the puzzle

```php
$puzzle->getRows();   // e.g. 4
$puzzle->getCols();   // e.g. 6
$puzzle->getWidth(); // source image width
$puzzle->getHeight(); // source image height

$piece = $puzzle->getPiece(0, 0);
$piece->getRow();
$piece->getCol();
$piece->getX();
$piece->getY();
$piece->getWidth();
$piece->getHeight();
$piece->toGdImage(); // GdImage of the rendered piece
```

## Step 4: Export to disk

```php
use ImagePuzzle\ExportOptions;

$result = $puzzle->export('/path/to/output_dir');
// or with options:
$options = new ExportOptions(
    format: ExportOptions::FORMAT_PNG,
    quality: 9,
    filenamePattern: 'piece_{row}_{col}.png',
    overwrite: true,
    manifestFilename: 'manifest.json'
);
$result = $puzzle->export('/path/to/output_dir', $options);

echo $result->getManifestPath();
$files = $result->getFiles();
$manifest = $result->getManifest();
```

The output directory is created if it does not exist. Each piece is saved according to the filename pattern, and a manifest JSON is written. Only filenames (no path separators) are allowed in the pattern and manifest filename so that all files stay inside the output directory.

## Parallel export

For large grids, you can export pieces in parallel when `ext-pcntl` is loaded and PHP is running in CLI:

```php
$exporter = new \ImagePuzzle\Exporter();
$result = $exporter->exportParallel(
    '/path/to/image.jpg',
    '/path/to/output_dir',
    $rows,
    $cols,
    new ExportOptions(),
    4,  // workers
    new RoundedCornersStyle(4)
);
```

When pcntl is unavailable or not in CLI, this falls back to sequential export.

## Security

When paths are user-supplied (e.g. from web input), resolve and restrict them to a base directory before calling the SDK (e.g. `realpath()` and check the path is under your allowed base). Export only writes files inside the given output directory.

Very large grids (e.g. thousands of rows or columns) can use a lot of memory and CPU; consider reasonable limits for your use case.

## Remainder rule

When the image width or height is not evenly divisible by the number of columns or rows, **the remainder pixels are given to the last column and last row**. So for a 100×50 image with 3 columns and 2 rows:

- Column widths: 33, 33, 34
- Row heights: 25, 25

This keeps the grid deterministic and easy to reconstruct from the manifest.
