# API and recipes

A compact map of the public API with tested recipes. The guides linked in
each section hold the full contracts. PHP namespaces start at `ImagePuzzle\`.

## PHP

| Task | API | Guide |
| --- | --- | --- |
| Build a playable puzzle directory | `Game\DefinitionBuilder::build($source, $dir, GameKind, $preset, $custom, $seed, AssetOptions)` | [GAME_MODES.md](GAME_MODES.md) |
| Build from a saved configuration | `Game\BuildConfiguration::fromJson()`, `DefinitionBuilder::buildConfigured()` | [GAME_MODES.md](GAME_MODES.md) |
| Open a built puzzle safely | `Game\PuzzleDirectory::open($dir)` | [OPERATIONS.md](OPERATIONS.md#paths-and-trust) |
| Validate a definition / a saved state | `Game\GameDefinition::fromJson()`, `Game\GameState::fromArray($definition, $data)` | [GAME_MODES.md](GAME_MODES.md#state-history-and-validation) |
| Play or replay on the server | `Game\GameEngine` (`start`, `apply`, `undo`, `redo`, `reset`, `shuffle`, `hint`) | [GAME_MODES.md](GAME_MODES.md) |
| SVG, PDF, ZIP bundle, reconstruction | `Export\SvgExporter`, `Export\PdfExporter`, `Export\BundleExporter`, `Export\Reconstruction` | [EXPORT.md](EXPORT.md) |
| Many puzzles, cache, storage | `Batch\BatchRunner`, `Cache\AssetCache`, `Storage\Publisher` | [BATCH.md](BATCH.md) |
| Split an image into piece files plus a manifest | `PuzzleGenerator`, `Exporter`, `ExportOptions`, edge styles | [USAGE.md](USAGE.md), [EDGE_STYLES.md](EDGE_STYLES.md) |
| Limits | `ResourceLimits` | [OPERATIONS.md](OPERATIONS.md#limits) |
| Errors | `Exception\ImagePuzzleException` and subclasses | [OPERATIONS.md](OPERATIONS.md#errors) |

### Every mode from one photo

<!-- snippet -->
```php
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\Modes;

$builder = new DefinitionBuilder();
foreach (Modes::available() as $kind) {
    $result = $builder->build('photo.jpg', "modes/{$kind->value}", $kind, 'easy', seed: 1);
    printf("%-9s %3d tiles  %s\n", $kind->value, count($result->definition->tiles), $result->definitionPath());
}
```

### Custom parameters and output formats

<!-- snippet -->
```php
use ImagePuzzle\Game\AssetOptions;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;

$builder = new DefinitionBuilder();
// A 4x6 jigsaw with JPEG pieces, a mobile resolution set and one atlas image for it.
$builder->build('photo.jpg', 'custom-jigsaw', GameKind::Jigsaw, 'custom', ['rows' => 4, 'cols' => 6], 7, new AssetOptions('jpeg', 85, ['mobile'], 'mobile'));
// A 3x4 sliding puzzle scrambled with 80 random moves (always solvable).
$builder->build('photo.jpg', 'custom-sliding', GameKind::Sliding, 'custom', ['rows' => 3, 'cols' => 4, 'scrambleMoves' => 80], 7);
```

Custom keys per mode are listed in [GAME_MODES.md](GAME_MODES.md#presets-version-1);
unknown keys are rejected.

### Replay a game on the server

<!-- snippet -->
```php
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameEngine;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Exception\IllegalActionException;

$definition = (new DefinitionBuilder())->build('photo.jpg', 'replay', GameKind::Swap, 'easy', seed: 3)->definition;
$engine = new GameEngine();
$state = $engine->start($definition);
while (!$state->completed) {
    $state = $engine->apply($definition, $state, $definition->rules()->hint($definition, $state->board) ?? throw new LogicException('no hint'));
}
echo "solved in {$state->moves} moves\n";

try {
    $engine->apply($definition, $state, ['type' => 'swap', 'a' => 'r0c0', 'b' => 'r0c1']);
} catch (IllegalActionException $e) {
    echo "rejected: {$e->getMessage()}\n"; // a completed game accepts no more moves
}
```

### Exports

<!-- snippet -->
```php
use ImagePuzzle\Export\PdfExporter;
use ImagePuzzle\Export\PdfOptions;
use ImagePuzzle\Export\Reconstruction;
use ImagePuzzle\Export\SvgExporter;
use ImagePuzzle\Export\SvgOptions;
use ImagePuzzle\Game\DefinitionBuilder;
use ImagePuzzle\Game\GameKind;
use ImagePuzzle\Game\PuzzleDirectory;

(new DefinitionBuilder())->build('photo.jpg', 'export-me', GameKind::Jigsaw, 'easy', seed: 9);
$puzzle = PuzzleDirectory::open('export-me');
(new SvgExporter())->write($puzzle, 'export-me.svg', new SvgOptions(image: SvgOptions::IMAGE_EMBED));
(new PdfExporter())->write($puzzle, 'export-me.pdf', new PdfOptions(paper: 'a4', title: 'Team photo'));
(new Reconstruction())->write($puzzle, 'export-me-board.png');
```

`BundleExporter::write($puzzle, 'puzzle.zip')` adds an offline ZIP when
ext-zip is loaded (`BundleExporter::supported()`).

## Command line

`vendor/bin/image-puzzle <command>`: `generate`, `validate`, `inspect`,
`reconstruct`, `svg`, `pdf`, `bundle`, `batch`, `capabilities`, `help`.
Exit codes: 0 ok, 1 failure, 2 usage, 3 invalid input, 4 missing capability,
5 resource limit. Reference: [CLI.md](CLI.md).

<!-- snippet -->
```bash
vendor/bin/image-puzzle generate photo.jpg cli-nonogram --kind=nonogram --preset=easy --json
vendor/bin/image-puzzle validate cli-nonogram
vendor/bin/image-puzzle inspect cli-nonogram --json
vendor/bin/image-puzzle capabilities --json
```

## Browser

| Task | API | Guide |
| --- | --- | --- |
| Drop-in element | `<image-puzzle-player src definition state locale persist>`, events `puzzle-*` | [PLAYER_API.md](PLAYER_API.md#web-component) |
| Configuration editor | `<image-puzzle-configurator src value name>`, form-associated | [PLAYER_API.md](PLAYER_API.md#configurator) |
| Imperative player | `createPlayer(container, options)` from `@offline-agency/image-puzzle-player` | [PLAYER_API.md](PLAYER_API.md#browser-player) |
| Headless rules | `createEngine({ definition, state })` from `@offline-agency/image-puzzle-engine` | [PLAYER_API.md](PLAYER_API.md#engine) |
| React / Vue | `<ImagePuzzlePlayer>` from `@offline-agency/image-puzzle-react` / `-vue` | [PLAYER_API.md](PLAYER_API.md#react-and-vue) |
| No bundler | `resources/dist/image-puzzle-player.iife.js` (global `ImagePuzzle`) | [PLAYER_API.md](PLAYER_API.md#no-bundler-use) |

Runnable pages: `examples/player/index.html` (plain JavaScript, every mode),
`element.html` (Web Component) and `configurator.html`; serve the repository
root over HTTP (`php -S 127.0.0.1:8000`) and open them under `/examples/player/`.

## Frameworks

| Framework | Package or recipe |
| --- | --- |
| Laravel + Livewire | `offline-agency/image-puzzle-livewire` ([LIVEWIRE.md](LIVEWIRE.md)) |
| Laravel + Filament | `offline-agency/image-puzzle-filament` ([FILAMENT.md](FILAMENT.md)) |
| CodeIgniter 4 | A tested example application ([CODEIGNITER.md](CODEIGNITER.md)) |
| Anything else | `DefinitionBuilder` plus the Web Component ([TUTORIAL.md](TUTORIAL.md)) |
