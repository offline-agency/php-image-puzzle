# Edge styles

Edge styles modify the appearance of each piece (e.g. rounded corners, tab/socket shapes). The library provides an interface and several built-in implementations, including classic jigsaw-style edges. Implementing `EdgeStyleInterface` and distributing via Composer is the supported way to add plugin styles.

## Config-driven edge style

You can define edge style in JSON and build it with `EdgeStyleFactory`:

```php
use ImagePuzzle\EdgeStyle\EdgeStyleFactory;
use ImagePuzzle\PuzzleGenerator;

$config = json_decode(file_get_contents('config/edge-config.json'), true);
$edgeStyle = EdgeStyleFactory::fromArray($config);
$generator = new PuzzleGenerator($edgeStyle);
```

Config must contain an `edge` object with `type` (one of: `no_edge`, `line`, `jigsaw`, `arc`, `half_circle`, `sinus`, `rounded_corners`) and optional `options` (e.g. `radius` for `rounded_corners`). From a file: `EdgeStyleFactory::fromFile('config/edge-config.json')` (JSON only). YAML is out of scope; see [README § Scope and future work](../README.md#scope-and-future-work).

**JSON Schemas:** [edge-config.schema.json](schemas/edge-config.schema.json), [puzzle-config.schema.json](schemas/puzzle-config.schema.json), [piece.schema.json](schemas/piece.schema.json). See [EDGE_EXAMPLES.md](EDGE_EXAMPLES.md) for one example image per edge type.

## EdgeStyleInterface

Implement this interface to define a custom style:

```php
namespace ImagePuzzle\EdgeStyle;

use GdImage;
use ImagePuzzle\PieceContext;

interface EdgeStyleInterface
{
    /**
     * Apply the edge style to a piece image.
     * May return the same image (modified in place) or a new GdImage.
     */
    public function apply(GdImage $piece, PieceContext $context): GdImage;
}
```

`PieceContext` provides:

- `getRow()`, `getCol()` – grid position
- `getX()`, `getY()` – top-left offset in the source image
- `getWidth()`, `getHeight()` – piece dimensions
- `getSourceWidth()`, `getSourceHeight()` – source image dimensions

Use these if your style needs to vary by position (e.g. different treatment for border vs inner pieces).

## Built-in styles

### NoEdgeStyle

Does nothing; returns the piece image unchanged. Used by default when you construct `PuzzleGenerator` with no argument.

### RoundedCornersStyle

Rounds the four corners of each piece by making corner pixels transparent. Constructor accepts a radius (default 4):

```php
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;

$style = new RoundedCornersStyle(4);
$generator = new PuzzleGenerator($style);
```

Radius is clamped so it never exceeds half the smaller side of the piece. No tab/socket or complex geometry—just a simple rounded rectangle effect.

## Classic edge styles

These styles replicate the tab/socket behavior of the original puzzle: right and bottom edges have tabs (opaque bumps), left and top edges have sockets (transparent dents), so adjacent pieces fit together.

### LineStyle

Rectangular pieces with no tab/socket (straight edges). Same effect as `NoEdgeStyle`; use when you want a named “line” style.

```php
use ImagePuzzle\EdgeStyle\LineStyle;

$generator = new PuzzleGenerator(new LineStyle());
```

### JigsawStyle

Classic jigsaw: ellipse-like tab/socket in the middle of each edge. Good default for a traditional puzzle look.

```php
use ImagePuzzle\EdgeStyle\JigsawStyle;

$generator = new PuzzleGenerator(new JigsawStyle());
```

### ArcStyle

Arc-shaped tab/socket along each edge. Slightly narrower segment than jigsaw.

```php
use ImagePuzzle\EdgeStyle\ArcStyle;

$generator = new PuzzleGenerator(new ArcStyle());
```

### HalfCircleStyle

Half-circle tab/socket on each edge. Diameter is half the edge length.

```php
use ImagePuzzle\EdgeStyle\HalfCircleStyle;

$generator = new PuzzleGenerator(new HalfCircleStyle());
```

### SinusStyle

Sinusoidal (wavy) edge: the boundary follows a sine wave along each edge (two cycles per edge). Gives a wavy cut so pieces fit with a wave pattern.

```php
use ImagePuzzle\EdgeStyle\SinusStyle;

$generator = new PuzzleGenerator(new SinusStyle());
```

## Implementing a custom style

1. Create a class implementing `EdgeStyleInterface`.
2. In `apply()`, either modify `$piece` in place (e.g. with `imagesetpixel`, `imagefilledellipse`) and return it, or create a new truecolor image, copy/modify, and return the new image. If you create a new image, ensure alpha is handled if you need transparency.
3. Pass your style to `PuzzleGenerator`:

```php
$generator = new PuzzleGenerator(new MyCustomEdgeStyle());
```

Keep the implementation simple and avoid heavy allocations if you export many pieces.
