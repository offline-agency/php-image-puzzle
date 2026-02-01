# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - Unreleased

### Added

- Split images into a grid of puzzle pieces with optional edge styles.
- Export each piece as PNG or JPEG plus a JSON manifest (rows/cols, piece dimensions, x/y offsets, filenames).
- Built-in edge styles: `NoEdgeStyle`, `RoundedCornersStyle`, and classic tab/socket styles (`LineStyle`, `JigsawStyle`, `ArcStyle`, `HalfCircleStyle`, `SinusStyle`).
- Config-driven edge style via JSON and `EdgeStyleFactory::fromArray()` / `fromFile()`.
- Custom split strategy via `DimensionSplitStrategy` (default: remainder to last row/column).
- Parallel export when `ext-pcntl` is loaded and PHP is CLI.
- Export hooks: `onPieceBeforeSave`, `onPieceAfterSave`, `onManifestBuild`.

[1.0.0]: https://github.com/gfabbian/php-image-puzzle/releases/tag/v1.0.0
