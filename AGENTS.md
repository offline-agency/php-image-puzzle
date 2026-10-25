# AGENTS.md

Guidance for coding agents (and people) changing this repository. The user
documentation starts at [README.md](README.md); [docs/API.md](docs/API.md)
is the compact API reference.

## What this is

`offline-agency/image-puzzle`: a PHP 8.4/8.5 library (ext-gd) that cuts
images into puzzle pieces, writes versioned manifests and game definitions
for eight modes, validates saved games, and ships a browser player. A
TypeScript workspace holds the headless engine, the Web Component player and
the React/Vue adapters. Laravel integrations (Livewire, Filament) and a
CodeIgniter 4 recipe live beside the core.

## Setup

```bash
composer install
npm ci
npm run build
npx playwright install chromium firefox webkit   # only for browser tests
```

## Commands

| Purpose | Command |
| --- | --- |
| PHP tests (parallel) | `vendor/bin/pest --parallel` |
| One PHP test file | `vendor/bin/pest tests/Game/GameEngineTest.php` |
| PHPStan level max | `vendor/bin/phpstan analyse --memory-limit=2G --no-progress` |
| Code style (fix / check) | `vendor/bin/pint` / `vendor/bin/pint --test` |
| JSON schemas valid, examples conform | `php tools/validate-schemas.php` |
| PHP and TypeScript rules agree | `php tools/conformance.php --check` |
| All PHP gates | `composer qa` |
| TypeScript | `npm run lint && npm run typecheck && npm test && npm run build` |
| Player copies in PHP packages current | `node tools/sync-player-bundle.mjs --check` |
| Browser tests | `npx playwright test --config packages/player/playwright.config.ts` |
| Livewire / Filament | `composer -d integrations/livewire install && composer -d integrations/livewire exec pest` (same for `filament`) |
| Fresh-project install from a commit | `tools/consumer/run.sh` (installs `git archive HEAD`: commit first) |
| CodeIgniter 4 recipe | `tools/codeigniter/run.sh` (network: Packagist) |
| Release automation scenarios | `composer release:test` |
| Without exif, pcntl, zip | `docker compose run --rm minimal sh -c 'composer install && vendor/bin/pest'` |

Examples:

```bash
php bin/image-puzzle generate photo.jpg out/jigsaw --kind=jigsaw --preset=easy --seed=1   # in this repository; consumers use vendor/bin
php bin/image-puzzle bundle out/jigsaw puzzle.zip
php examples/player/generate.php     # regenerates examples/player/assets
php examples/showcase.php            # regenerates docs/images/showcase
```

## Map

| Path | Contents |
| --- | --- |
| `src/PuzzleGenerator.php`, `src/Puzzle.php`, `src/Piece.php` | Cutting an image into pieces |
| `src/Geometry`, `src/SplitStrategy`, `src/EdgeStyle` | Piece shapes, topologies (grid, hex, triangle), edge profiles |
| `src/Export` | Piece images, manifest v2, parallel export, atlas, SVG, PDF, ZIP bundle, path checks |
| `src/Game` | Definitions, `GameEngine`, `GameState`, presets, PRNG, `DefinitionBuilder` |
| `src/Game/Rules` | One rules class per mode (the PHP side of the shared rules) |
| `src/Batch`, `src/Cache`, `src/Storage` | Batch builds, asset cache, atomic publication (Flysystem optional) |
| `src/Cli` | `bin/image-puzzle` |
| `packages/engine` | Headless TypeScript engine; `src/rules` mirrors `src/Game/Rules` |
| `packages/player` | Renderer and `<image-puzzle-player>`; built bundles in `dist/` |
| `packages/react`, `packages/vue` | Thin adapters over the player |
| `integrations/livewire`, `integrations/filament` | Laravel packages with their own Composer setup and tests |
| `integrations/codeigniter4` | Example files verified by `tools/codeigniter/run.sh` |
| `docs/schemas` | JSON Schemas: manifest v1/v2, game definition and state v1 |
| `conformance` | Fixtures both rule implementations must reproduce |
| `resources/dist` | IIFE player shipped with the PHP package (generated) |
| `tools/consumer`, `tools/js-consumer` | Installed-package smoke tests (PHP; React/Vue/SSR) |

## Contracts

- The core requires only PHP, ext-gd and ext-json. Framework code goes in
  `integrations/`, never in `src/`.
- Rules exist once per language: `src/Game/Rules` and
  `packages/engine/src/rules`. A rule change updates both and the
  `conformance/` fixtures (`php tools/conformance.php`); renderers and
  adapters never decide legality.
- Output is deterministic: the same image, configuration and seed give the
  same definition ID and files. Changing generated output, a manifest, a
  definition or a state document needs the schema in `docs/schemas`, a
  version decision and a [MIGRATION.md](docs/MIGRATION.md) entry.
- Requests never choose filesystem paths. The library validates output
  paths (`Export\PathGuard`) and decoding limits (`ResourceLimits`); keep
  both checks on every new entry point.
- Generated files are produced by their scripts, never edited by hand:
  `resources/dist` and `integrations/*/resources/dist` (`npm run
  sync:bundles`), `conformance/` (`composer conformance`),
  `examples/player/assets`, `docs/images/showcase`.
- PHPStan stays at level max without baselines, `ignoreErrors` or
  suppression comments. A behaviour fix starts with a failing test.
- Documentation PHP blocks are checked by `tests/Docs`; blocks marked
  `<!-- snippet -->` are executed. Update them with the API.
- Versions and tags come from the release workflow
  ([docs/release/RUNBOOK.md](docs/release/RUNBOOK.md)). Do not tag, publish
  or edit `version` fields by hand.
