# Examples

## Runnable examples

Each of these is run by a test or CI job, so it matches the shipped code.

| Target | Example | How to run | Verified by |
| --- | --- | --- | --- |
| Plain PHP: cut and export | `examples/generate.php` | `php examples/generate.php photo.jpg 3 4 out` | `tests/Docs/ExampleScriptsTest.php` |
| Plain PHP: pieces, exploded view, reconstruction | `examples/showcase.php` | `php examples/showcase.php build/showcase` | `tests/Docs/ShowcaseTest.php` |
| Every game mode, plain JavaScript (`createPlayer`) | `examples/player/index.html` | `php -S 127.0.0.1:8000`, open `/examples/player/` | `packages/player/e2e/plain.spec.ts` |
| Web Component, keyboard and pointer | `examples/player/element.html` | as above, `/examples/player/element.html` | `element.spec.ts`, `a11y.spec.ts` |
| Configurator (authoring form) | `examples/player/configurator.html` | as above | `configurator.spec.ts`, `journey.spec.ts` |
| Demo site from your own picture | [`examples/demo`](../examples/demo/README.md) | `php examples/demo/build.php build/demo` | `demo.spec.ts`, `tests/Docs/ExampleScriptsTest.php`, `tools/consumer/cli.php` |
| React and Vue adapters, SSR | `tools/js-consumer/app` (`react-app.js`, `vue-app.js`) | `tools/js-consumer/run.sh` | `tools/js-consumer/e2e/adapters.spec.ts` |
| Livewire | `integrations/livewire/workbench` | `composer -d integrations/livewire serve` | Pest and Playwright suites ([LIVEWIRE.md](LIVEWIRE.md#development)) |
| Filament | `integrations/filament/workbench` | `composer -d integrations/filament serve` | Pest and Playwright suites ([FILAMENT.md](FILAMENT.md#development)) |
| CodeIgniter 4 | `integrations/codeigniter4` | `tools/codeigniter/run.sh` | PHPUnit and an HTTP smoke test ([CODEIGNITER.md](CODEIGNITER.md)) |
| CLI | `bin/image-puzzle` | `vendor/bin/image-puzzle help` | `tests/Cli`, `tools/consumer/cli.php` |

The keyboard and pointer controls of every mode are listed in
[PLAYER_API.md](PLAYER_API.md#rendering-and-input).

## Use cases

The two blocks below use the lower-level cutting API. For complete games,
start from `DefinitionBuilder` ([TUTORIAL.md](TUTORIAL.md)); for many images,
the batch runner adds resume, caching and cancellation ([BATCH.md](BATCH.md)).

### 1. Web game: generate pieces and return metadata/coordinates JSON

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

### 2. Server-side batch pipeline: generate puzzles for multiple images

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
