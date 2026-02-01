# Real-world use cases

## 1. Web game: generate pieces and return metadata/coordinates JSON

Generate puzzle pieces from an image for a browser-based game. Save each piece as an image file and return a JSON manifest with rows/cols, filenames, and coordinates so the front end can position and drag pieces.

```php
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\EdgeStyle\RoundedCornersStyle;

$inputImage = '/path/to/game_image.jpg';
$outputDir = __DIR__ . '/public/puzzles/game_123';
$cols = 6;
$rows = 4;

$generator = new PuzzleGenerator(new RoundedCornersStyle(4));
$puzzle = $generator->generateFromFile($inputImage, $rows, $cols);
$result = $puzzle->export($outputDir);
// manifest.json is written automatically; use $result->getManifestPath() and $result->getFiles()
```

The front end can fetch `manifest.json` and use `x`, `y`, `width`, `height`, and `filename` to lay out and validate piece placement.

---

## 2. Server-side batch pipeline: generate puzzles for multiple images

Preprocess a set of images (e.g. product photos, gallery) and generate puzzle assets for each. Run from CLI or a queue worker.

```php
use ImagePuzzle\PuzzleGenerator;
use ImagePuzzle\EdgeStyle\NoEdgeStyle;

$imageList = [
    '/data/images/photo1.jpg',
    '/data/images/photo2.png',
];
$baseOutputDir = '/data/puzzles';
$cols = 4;
$rows = 3;

$generator = new PuzzleGenerator(new NoEdgeStyle());
foreach ($imageList as $inputPath) {
    if (!is_readable($inputPath)) {
        fprintf(STDERR, "Skip (unreadable): %s\n", $inputPath);
        continue;
    }
    $outputDir = $baseOutputDir . '/' . pathinfo($inputPath, PATHINFO_FILENAME);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    try {
        $puzzle = $generator->generateFromFile($inputPath, $rows, $cols);
        $puzzle->export($outputDir);
        echo "OK: " . pathinfo($inputPath, PATHINFO_FILENAME) . "\n";
    } catch (Throwable $e) {
        fprintf(STDERR, "Error %s: %s\n", $inputPath, $e->getMessage());
    }
}
```

Run from cron or a job queue; keep `$cols`/`$rows` in config so you can tune the pipeline without code changes.
