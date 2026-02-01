# Image Puzzle

[![CI](https://github.com/gfabbian/php-image-puzzle/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/gfabbian/php-image-puzzle/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/gfabbian/php-image-puzzle/graph/badge.svg)](https://codecov.io/gh/gfabbian/php-image-puzzle)
[![Downloads](https://img.shields.io/packagist/dt/offline-agency/image-puzzle)](https://packagist.org/packages/offline-agency/image-puzzle)
[![PHP](https://img.shields.io/badge/PHP-8.4%20%7C%208.5-777BB4?logo=php)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Split images into a grid of puzzle pieces, optionally apply edge styles, and export each piece as PNG (or JPEG) plus a JSON manifest with metadata (rows/cols, piece dimensions, x/y offsets, filenames).

**Requirements:** PHP 8.4 or 8.5, [ext-gd](https://www.php.net/manual/en/book.image.php).

## Installation

```bash
composer require offline-agency/image-puzzle
```

## Quickstart

**Recommended (three steps):**

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;

$generator = new PuzzleGenerator(new RoundedCornersStyle(4));
$puzzle = $generator->generateFromFile('photo.jpg', 4, 6);
$result = $puzzle->export('/path/to/output_dir');

echo "Exported " . count($result->getFiles()) . " files.\n";
echo "Manifest: " . $result->getManifestPath() . "\n";
```

**One-shot (single call):**

```php
$result = PuzzleGenerator::generateAndExport('photo.jpg', '/path/to/output_dir', 4, 6, new RoundedCornersStyle(4));
```

**Parallel export** (when `ext-pcntl` is loaded and PHP is CLI) for large grids:

```php
$exporter = new \ImagePuzzle\Exporter();
$result = $exporter->exportParallel('photo.jpg', '/path/to/output_dir', 4, 6, new \ImagePuzzle\ExportOptions(), 4, new RoundedCornersStyle(4));
```

## Export and manifest

Each piece is saved as an image file (PNG by default). A `manifest.json` is written in the same directory with:

- `source` – basename of the source image
- `rows`, `cols` – grid size
- `sourceWidth`, `sourceHeight` – original dimensions
- `pieces` – array of objects: `row`, `col`, `x`, `y`, `width`, `height`, `filename`

Example manifest snippet:

```json
{
    "source": "photo.jpg",
    "rows": 4,
    "cols": 6,
    "sourceWidth": 1200,
    "sourceHeight": 800,
    "pieces": [
        {
            "row": 0,
            "col": 0,
            "x": 0,
            "y": 0,
            "width": 200,
            "height": 200,
            "filename": "piece_0_0.png"
        }
    ]
}
```

## Splitting rule

When the image size is not evenly divisible by rows/cols, **remainder pixels are assigned to the last row and last column**. For example, a 100×50 image with 3 columns gives column widths 33, 33, 34; with 2 rows gives row heights 25, 25.

## Documentation

- [docs/USAGE.md](docs/USAGE.md) – Step-by-step usage
- [docs/EXPORT.md](docs/EXPORT.md) – Export formats and options
- [docs/EDGE_STYLES.md](docs/EDGE_STYLES.md) – Edge styles and config-driven style (JSON schema, `EdgeStyleFactory`)
- [docs/EDGE_EXAMPLES.md](docs/EDGE_EXAMPLES.md) – Example image for every edge type
- [docs/SPLIT_STRATEGY.md](docs/SPLIT_STRATEGY.md) – Customizing grid split strategy

JSON schemas for config and manifest: [docs/schemas/](docs/schemas/) (`edge-config.schema.json`, `puzzle-config.schema.json`, `piece.schema.json`, `manifest.schema.json`). Config files are JSON-only; to use YAML or other formats, decode in your code (e.g. `yaml_parse_file()` if ext-yaml is available) and pass the resulting array to `EdgeStyleFactory::fromArray($config)`.

## Example script

```bash
php examples/generate.php input.jpg 4 6 output_dir
php examples/generate.php input.jpg 4 6 output_dir --config=config/edge-config.json
```

Prints the manifest path and a short summary. Use `--config=<path>` to load edge style from a JSON config file. To use YAML, decode in your code and pass the array to `EdgeStyleFactory::fromArray()`.

## Image formats (GD)

Supported input formats: PNG, JPEG, GIF (as detected by GD). The exporter supports PNG and JPEG output.

## Security

- When paths are user-supplied (e.g. from web input), resolve and restrict them to a base directory before calling the SDK (e.g. `realpath()` and check prefix).
- Export only writes files inside the given output directory; `filenamePattern` and `manifestFilename` must not contain path separators.
- Very large grids (e.g. thousands of rows/cols) can use a lot of memory; consider reasonable limits.

## Troubleshooting

- **"cannot read from ..."** – Ensure the file path is readable and the file is a valid image.
- **"unsuported image type"** – GD may not support that type; check `imagetypes()` or use JPEG/PNG.
- **GD not found** – Install ext-gd: `apt-get install php-gd`, or enable it in `php.ini`.

## Extensions

Custom edge styles implement `EdgeStyleInterface` (see [docs/EDGE_STYLES.md](docs/EDGE_STYLES.md)). Built-in styles include `NoEdgeStyle`, `RoundedCornersStyle`, and classic tab/socket styles (`LineStyle`, `JigsawStyle`, `ArcStyle`, `HalfCircleStyle`, `SinusStyle`). For per-side styles use `CompositeEdgeStyle`; for per-piece styles (e.g. by row/col or border vs inner) use `ResolverEdgeStyle`. You can create styles from JSON config with `EdgeStyleFactory::fromArray()` or `EdgeStyleFactory::fromFile()`. Third-party packages can provide additional styles as plugins.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security

If you discover any security-related issues, please email <support@offlineagency.com> instead of using the issue
tracker.

## Credits
Inspired by the original [pear/Image_Puzzle](https://github.com/pear/Image_Puzzle) (archived). This project is a from-scratch implementation with a modern API (PHP 8.4+, Composer, edge styles, export manifest).
- [Offline Agency](https://github.com/offline-agency)
- [Giacomo Fabbian](https://github.com/Giacomo92)
- [All Contributors](https://github.com/offline-agency/laravel-webex/graphs/contributors)

## About us

Offline Agency is a web design agency based in Padua, Italy. You'll find an overview of our
projects [on our website](https://offlineagency.it/).

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
