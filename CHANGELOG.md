# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Release tags have no `v` prefix (`1.0.2`, not `v1.0.2`). Dates are the dates of
the tagged commits.

## [Unreleased]

This is the next major version (2.0.0). See [docs/MIGRATION.md](docs/MIGRATION.md)
for upgrading from 1.x.

### Breaking

- Jigsaw-family pieces are padded to their rendered bounds; the manifest is
  version 2 (rendered rectangles, outlines, geometry) and `onManifestBuild`
  may only change the `extensions` object.
- Edge style configuration is strict: unknown keys, non-integer radii and
  options on styles that take none are rejected. Version 1 schemas moved to
  `docs/schemas/v1`; configuration files migrate through `PuzzleConfig`.
- `CompositeEdgeStyle` requires complementary opposite sides;
  `ClassicEdgeHelper` was removed. Image quality is validated per format.
- Requires PHP 8.4 or 8.5 (`~8.4.0 || ~8.5.0`).

### Added

- Real interlocking jigsaw geometry with shared complementary edges and a
  flat perimeter, on rectangular, hexagonal and triangular grids.
- Versioned game definitions and state documents with one rules module per
  mode: sliding, swap, rotation, strips, jigsaw, memory, tangram and
  nonogram, with versioned difficulty presets.
- A headless TypeScript engine conforming to the PHP rules (shared
  conformance fixtures), a plain JavaScript player, the
  `<image-puzzle-player>` and `<image-puzzle-configurator>` Web Components,
  React and Vue adapters.
- Livewire and Filament integrations (in `integrations/`, not yet on
  Packagist): authorised definition and asset routes, server-validated
  saves, a configuration field, generation and download actions.
- The `image-puzzle` CLI: generate, validate, inspect, reconstruct, svg,
  pdf, bundle, batch and capabilities, with documented exit codes.
- WebP output, piece resolution sets and atlases; SVG outlines, printable
  PDF and self-contained offline ZIP bundles.
- Batch builds with progress, cancellation, resume and concurrency; a
  validated generated-asset cache; publication to filesystems, streams and
  Flysystem (S3-compatible) storage.
- Resource limits applied before decoding, EXIF orientation without
  requiring ext-exif, and parallel export with validated worker results.
- A CodeIgniter 4 recipe verified in a fresh application, a demo site
  builder (`examples/demo/build.php`), a tutorial, operations, migration and
  API guides, `AGENTS.md` and `llms.txt`. Documentation code blocks and links
  are checked by the test suite.

### Fixed

- Rounded corners erased square corners and ignored the documented radius.
- Invalid grid sizes and piece indices were accepted.
- Forked workers could continue into the caller's code, report success
  after a crash, ignore `overwrite: false` or leave children behind.
- Exports could follow symlinks out of the base directory, collide on file
  names and leave partial output or a stale manifest after a failure.
- Composite styles copied only one-pixel strips; the sinus edge produced
  identical tabs and sockets and changed the outer border.
- The hardcoded `version` in composer.json (always `1.0.0`) disagreed with the
  `1.0.1` and `1.0.2` tags.

## [1.0.2] - 2026-02-10

### Added

- Tests for composite edge styles, the edge style factory, the image loader
  and pieces.

## [1.0.1] - 2026-02-02

### Changed

- README updates.

## [1.0.0] - 2026-02-02

### Added

- Split images into a grid of puzzle pieces with optional edge styles.
- Export each piece as PNG or JPEG plus a JSON manifest (rows/cols, piece dimensions, x/y offsets, filenames).
- Built-in edge styles: `NoEdgeStyle`, `RoundedCornersStyle`, and classic tab/socket styles (`LineStyle`, `JigsawStyle`, `ArcStyle`, `HalfCircleStyle`, `SinusStyle`).
- Config-driven edge style via JSON and `EdgeStyleFactory::fromArray()` / `fromFile()`.
- Custom split strategy via `DimensionSplitStrategy` (default: remainder to last row/column).
- Parallel export when `ext-pcntl` is loaded and PHP is CLI.
- Export hooks: `onPieceBeforeSave`, `onPieceAfterSave`, `onManifestBuild`.

[Unreleased]: https://github.com/offline-agency/php-image-puzzle/compare/1.0.2...HEAD
[1.0.2]: https://github.com/offline-agency/php-image-puzzle/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/offline-agency/php-image-puzzle/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/offline-agency/php-image-puzzle/tree/1.0.0
