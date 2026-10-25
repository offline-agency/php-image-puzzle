# When this library fits

Use it when you need **puzzle assets made on the server from your own
images**, that are **reproducible** and can be **played and checked** with
the same rules in the browser and in PHP. If you only need rectangular crops,
or a puzzle that never leaves one browser, simpler tools are enough; the
comparison below says where the line is.

## Concrete needs it covers

| Need | What the library does | Evidence in this repository |
| --- | --- | --- |
| Rectangular slicing with exact coverage | Splits any image into a grid whose cells cover it exactly once (remainder to the last row and column), with a manifest of offsets | `tests/SplitStrategyTest.php`, `tests/PuzzleGeneratorTest.php` |
| Real jigsaw pieces | Neighbours share one generated edge, tabs and sockets are complementary, the outer border is flat; pieces are padded PNG/WebP images with outlines; hexagonal and triangular grids too | `tests/Geometry/JigsawGeometryTest.php`, `tests/Geometry/PolygonTopologyTest.php`, `tests/Docs/ShowcaseTest.php` (pieces reassemble to the source with zero pixel difference) |
| Server-generated game assets | Eight modes with versioned presets; the definition references only files inside its directory | `tests/Game/*`, `tools/consumer/cli.php` (every advertised mode built by the installed CLI) |
| Reproducible output | The same image, configuration and seed give the same definition ID (`gd1-` + SHA-256 of the canonical document) and tiles | `tests/Game/GameDefinitionTest.php`, `conformance/mode-*.json` |
| One set of rules, two languages | PHP and TypeScript rules replay the same fixtures; the server validates saved games the browser produced | `tools/conformance.php --check`, `packages/engine/test`, `integrations/livewire/tests/PuzzlePlayerTest.php` |
| Framework-neutral integration | The core depends only on ext-gd and ext-json; framework code lives in separate packages and recipes | `composer.json`, `integrations/`, [CODEIGNITER.md](CODEIGNITER.md) |
| Print and offline use | SVG outlines, PDF with cut lines, self-contained ZIP bundles | `tests/Export/*`, `packages/player/e2e/bundle.spec.ts` |

## Compared with plain GD cropping

`imagecrop()` (or a framework's image library, such as CodeIgniter's
`Image::crop()`) cuts rectangles. That is enough when a "puzzle" means a
grid of rectangles you lay out yourself. You would still write:

- the grid arithmetic for sizes that do not divide evenly, and a manifest of
  offsets;
- interlocking shapes: complementary tab/socket curves shared by both
  neighbours, antialiased masks that leave no seams, padding for tabs;
- limits that reject huge or malformed images *before* decoding them;
- safe publication (no partial output, no writes outside a base directory);
- game rules, scrambles that are always solvable, completion checks, saved
  game validation, and a player.

This library is that code, tested. It uses GD as well, so it adds no image
dependency.

## Compared with browser-only puzzles

JavaScript puzzle components cut the image in the browser. They need no PHP
and fit a single page where nothing is stored. Generating on the server
instead means:

- the pieces are computed once, cached and served as small images instead
  of every visitor decoding the full photo and computing shapes;
- the puzzle is a file with an ID: the same puzzle can be printed (PDF/SVG),
  shared, bundled for offline play or rebuilt identically later;
- a saved or "completed" game can be validated on the server with the same
  rules, so progress and results can be stored or rewarded;
- the browser player is still included: plain JavaScript, a Web Component,
  React, Vue, Livewire and Filament wrappers share one engine.

The trade-off is a PHP build step and disk space for the assets.

## Not a fit

- You need puzzles of non-image content, 3D, or real-time multiplayer.
- You cannot run PHP 8.4+ with ext-gd anywhere in your pipeline.
- You need piece grouping (moving connected pieces together) in the jigsaw
  player: pieces lock individually today ([PLAYER_API.md](PLAYER_API.md#rendering-and-input)).

No performance claims are made here; `composer benchmark` and
`tools/measure-assets.php` measure your own images on your own machine.
