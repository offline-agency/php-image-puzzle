# Feature matrix (F01–F26)

Features added after `1.0.2`, kept apart from the defects in
[gap-matrix.md](gap-matrix.md). Fresh command results are in
[verification.md](verification.md).

States:

- **verified**: the acceptance evidence exists as automated tests or
  checks, and they passed in this session;
- **verified, with limits**: as above, with a named part that is manual,
  documentation only or not exercised;
- **blocker**: part of the acceptance needs an action outside this
  repository; the step and its procedure are named.

Test names are quoted exactly. PHP tests run in the CI `tests` job; `js`
runs `npm test`; `browser` runs `packages/player/e2e` on Chromium,
Firefox, WebKit and two mobile emulations.

## Summary

| ID | Feature | Status |
| --- | --- | --- |
| F01 | Sliding, swap, rotation and strips | verified |
| F02 | Memory with image pairs and image/text | verified, with limits (text is a label on the second card) |
| F03 | Hexagonal and triangular jigsaw | verified |
| F04 | Image-backed tangram | verified |
| F05 | Nonogram with bounded uniqueness check | verified |
| F06 | Shared headless TypeScript engine | verified |
| F07 | Plain JavaScript player | verified |
| F08 | Web Component player | verified |
| F09 | Livewire player | verified |
| F10 | Filament widget, field and configuration editor | verified |
| F11 | Vue player | verified |
| F12 | React player | verified |
| F13 | Self-contained ZIP bundle | verified |
| F14 | Composer-installed CLI | verified |
| F15 | Versioned difficulty presets | verified |
| F16 | Saved games, undo/redo, reset | verified |
| F17 | SVG outlines and printable PDF | verified |
| F18 | WebP and multiple resolutions | verified |
| F19 | Spritesheet/atlas | verified |
| F20 | Batch generation, progress, cancellation | verified |
| F21 | Generated-asset cache | verified |
| F22 | Stream/storage output, optional S3-capable adapter | verified, with limits (Flysystem in-memory adapter; no S3 or MinIO run) |
| F23 | Shared configurator and Filament authoring | verified |
| F24 | Distribution linked to automatic releases | blocker (artifacts built and consumed from packs; registries not set up, releases not active) |
| F25 | Accessible controls and mobile interactions | verified, with limits (finger drags of pieces and screen-reader output are manual) |
| F26 | Cross-adapter compatibility and upgrade contract | verified, with limits (upgrade contract is the lockstep policy plus schema versioning) |

## Details

### F01 Sliding, swap, rotation and strips

- Commit: `0a0b7ce`. Rules: `src/Game/Rules`, mirrored in
  `packages/engine/src/rules`.
- Tests: `tests/Game/ModeRulesTest.php`: "sliding scrambles are
  reproducible, unsolved and reachable by construction", "swap completion
  compares stable ids even when every tile looks identical", "rotation
  follows the clockwise orientation convention", "strips move removes and
  inserts, with explicit remainder handling";
  `packages/player/e2e/plain.spec.ts`: "sliding: keyboard only (focus,
  arrows, Enter, undo)".

### F02 Memory

- Commit: `f705df7`.
- Tests: `tests/Game/MemoryTest.php`: "deals two cards per pair with stable
  IDs and one image region per pair", "shows a text label on the second
  card of each pair", "matches a pair, keeps a mismatch face up and turns it
  down on the next flip"; `packages/player/e2e/memory.spec.ts`: "text labels
  show on the second card of each pair".
- Limit: an image/text association is an image card paired with a card
  that shows its text label; two different images per pair are not
  supported.

### F03 Hexagonal and triangular jigsaw

- Commit: `c377d48`.
- Tests: `tests/Geometry/PolygonTopologyTest.php`: "every internal boundary
  is shared by exactly two cells in opposite directions", "shared
  boundaries are exact complements and outlines are simple", "exact mode
  reconstructs every source pixel with exactly one owner", "a hexagonal
  export records its topology in the manifest";
  `tests/Game/JigsawGameTest.php`: "jigsaw definitions wrap real export
  manifests of every topology".

### F04 Image-backed tangram

- Commit: `b05fd51`.
- Tests: `tests/Game/TangramTest.php`: "partitions the square into seven
  pieces of total area 16 units without overlap", "validates every target
  in the library", "is complete only when every piece lies exactly on its
  slot"; `packages/player/e2e/tangram.spec.ts`: "draws the target silhouette
  and seven focusable pieces with the picture clipped to their outline".

### F05 Nonogram

- Commit: `d4f11cd`.
- Tests: `tests/Game/NonogramTest.php`: "agrees with brute force on every
  3x3 grid", "stops at the budget and never calls an undecided grid unique",
  "generates the pictured grid and proves it unique", "labels an undecided
  grid as unverified, never unique"; `packages/player/e2e/nonogram.spec.ts`.

### F06 Shared headless engine and conformance

- Commit: `9741c89`.
- Tests: `packages/engine/test/conformance.test.ts`: "accepts the PHP
  definition and verifies its id", "produces the same solved board and
  scrambles per seed", "lists the same legal actions in the same order",
  "accepts and rejects the same imported boards";
  `tests/Game/GameEngineTest.php`: "committed conformance fixtures match the
  PHP rules".
- Check: `php tools/conformance.php --check`.

### F07 Plain JavaScript player

- Commit: `74fc66c`. No-bundler page: `examples/player/index.html` (IIFE);
  ESM: `examples/player/element.html`.
- Tests: `packages/player/e2e/plain.spec.ts`: "save, restore and destroy
  from the host page", "switching puzzles quickly keeps only the last one",
  and a load-and-complete test per mode; `packages/player/test/player.test.ts`:
  "destroy releases engine, listeners, timers and DOM";
  `tools/js-consumer` (ESM imports from packed tarballs).

### F08 Web Component player

- Commit: `74fc66c`.
- Tests: `packages/player/test/element.test.ts`: "loads its src attribute
  on connection and dispatches composed, bubbling events", "honours
  properties assigned before the element was upgraded", "suspends while
  detached, keeps state, and resumes on reconnection", "exposes the same
  imperative API and event stream as the plain player";
  `packages/player/e2e/element.spec.ts`: "properties beat attributes and
  objects are never serialised into attributes". Styling:
  `packages/player/test/adapter-contract.ts`: "applies theme and themeClass
  to the player host", and the dark-mode accessibility runs.

### F09 Livewire player

- Commit: `ad5766d`.
- Tests: `integrations/livewire/tests/PuzzlePlayerTest.php`:
  `test_renders_the_element_inside_wire_ignore_with_the_authorized_definition_url`,
  `test_rejects_tampered_foreign_and_oversized_states_without_storing_them`;
  `integrations/livewire/e2e/livewire.spec.ts`: "leaving through
  wire:navigate flushes the pending save", "a move is saved through
  Livewire and restored after a reload", "a failed restore request is
  retried instead of dropping the saved progress".
- Consumer: the Testbench workbench application in a real browser.

### F10 Filament widget, field and configuration editor

- Commit: `92a779e`.
- Tests: `integrations/filament/tests/AccessTest.php`:
  `testTheWidgetPlaysAConfiguredPuzzle`,
  `testOtherTenantsAndGuestsAreRejectedOnEveryRoute`;
  `integrations/filament/tests/AuthoringTest.php`:
  `testEditLoadsSavesAndReloadsTheConfiguration`;
  `integrations/filament/e2e/filament.spec.ts`: "an author configures,
  saves, reloads, generates, plays and downloads a puzzle", "another team's
  puzzle is refused in the panel and on every package route".

### F11 Vue player

- Commit: `350879a`.
- Tests: `packages/vue/test/vue.test.ts`: "validates emitted payloads and
  forwards them unchanged", "remounts with new creation options when the
  key changes"; shared contract: "disposes the player, its DOM and its
  listeners on unmount"; `packages/vue/test/ssr.test.ts`: "renders the
  container without touching the DOM or warning"; `tools/js-consumer`
  (types, SSR, three browsers).

### F12 React player

- Commit: `350879a`.
- Tests: `packages/react/test/react.test.tsx`: "survives the Strict Mode
  setup/cleanup/setup cycle without duplicates or leaks", "exposes load,
  reset and state operations through the ref";
  `packages/react/test/ssr.test.tsx`: "renders the container without
  touching the DOM or warning"; shared contract: "calls the newest handlers
  without reloading".

### F13 Self-contained ZIP bundle

- Commits: `0377423`, `e35385d`.
- Tests: `packages/player/e2e/bundle.spec.ts`: "extracted bundles play over
  HTTP using only their own files", "extracted bundles play from file://";
  `tests/Export/BundleExporterTest.php`: "builds a page that needs no
  network and holds the definition inline", "writes sorted, deterministic
  entries under one folder".

### F14 Composer-installed CLI

- Commits: `0377423`, `bca000d`.
- Tests: `tests/Cli/ApplicationTest.php`: "builds every mode it
  advertises", "returns the exit codes as process status";
  `tools/consumer/cli.php` generates and validates every mode through
  `vendor/bin/image-puzzle` in a fresh Composer project.

### F15 Versioned difficulty presets

- Commit: `0a0b7ce`. `Game\Presets::VERSION` is recorded in every
  definition; `conformance/presets.json` binds the TypeScript side.
- Tests: `tests/Game/GameDefinitionTest.php`: "the id changes with the
  source digest, seed, preset and configuration", "tampered, stale or
  inconsistent documents are rejected" (includes an unknown preset);
  `packages/engine/test/build-config.test.ts`: "copies the board shape of
  every PHP preset".

### F16 Saved games, undo/redo, reset

- Commits: `0a0b7ce`, `9741c89`, `21fb192`.
- Tests: `tests/Game/GameEngineTest.php`: "state export and import
  round-trip and match the schema", "incompatible or corrupt imported state
  is rejected", "actions, undo and redo update board, history and
  counters", "reset, shuffle and hint follow the documented semantics";
  `packages/engine/test/persistence.test.ts`: "a missing entry is not an
  error; corrupt or stale entries keep the game and the data".

### F17 SVG outlines and printable PDF

- Commit: `0377423`.
- Tests: `tests/Export/VectorExportTest.php`: "reassembles the source
  picture when rendered", "matches the alpha mask of every raster piece",
  "prints the board at the requested physical size and position", "draws
  cut lines on the piece outlines". The rendered checks use `rsvg-convert`,
  `pdftoppm` and `qpdf`, which this machine has and CI installs.

### F18 WebP and multiple resolutions

- Commit: `870eada`.
- Tests: `tests/Game/PieceImagesTest.php`: "writes smaller sets with the
  same IDs, file names and geometry", "reports a missing WebP encoder as a
  capability error before writing anything";
  `tests/Export/ExportSafetyTest.php`: "WebP export when the encoder is
  available"; `packages/player/e2e/assets.spec.ts`: "responsive pieces pick
  a set from their displayed width and the pixel density".

### F19 Spritesheet/atlas

- Commit: `870eada`.
- Tests: `tests/Game/PieceImagesTest.php`: "packs deterministically, in
  input order, with padding between frames", "packs the full pieces into an
  atlas whose frames hold their exact pixels";
  `packages/player/e2e/assets.spec.ts`: "an atlas build loads one image for
  every piece"; `packages/player/test/player.test.ts`: "draws atlas pieces
  into one canvas per frame and loads the atlas once".

### F20 Batch generation, progress, cancellation

- Commits: `f705ef6`, `266abd6`.
- Tests: `tests/Batch/BatchRunnerTest.php`: "builds every item and reports
  progress", "can be signalled from another process through a file",
  "resume verified items and rebuild the others", "builds items in parallel
  worker processes with the same results", "fails the running items instead
  of spinning when waiting for workers fails";
  `integrations/livewire/tests/BuildPuzzleJobTest.php`: "skips the item when
  its Bus batch or cancel file is cancelled".

### F21 Generated-asset cache

- Commit: `f705ef6`.
- Tests: `tests/Cache/AssetCacheTest.php`: "change with the source, the
  configuration, the output options and the engine", "drop a corrupt,
  extended or mislabelled entry and rebuild it", "serialise concurrent
  writers of one key", "invalidate entries on request".

### F22 Stream/storage output

- Commit: `f705ef6`.
- Tests: `tests/Storage/StorageTest.php`: "writes, overwrites, streams,
  reads and deletes objects", "never moves the reference when an upload
  fails part-way", "publishes through Flysystem and wraps its errors",
  "rejects keys that could leave the root".
- Limit: the object-store path is exercised through Flysystem with
  `league/flysystem-memory`; S3 compatibility comes from Flysystem's S3
  adapter and was not run against S3 or MinIO.

### F23 Shared configurator and Filament authoring

- Commits: `a9196db`, `92a779e`.
- Tests: `packages/player/test/configurator.test.ts`: "loads a saved
  configuration and reports edits as documents", "rejects a crop too small
  for the board"; `packages/player/e2e/configurator.spec.ts`: "dragging the
  frame corner and body crops the picture", "the form submits the
  configuration and blocks invalid ones";
  `tests/Game/BuildConfigurationTest.php`: "builds from a configuration,
  cropping the upright source first";
  `integrations/filament/e2e/filament.spec.ts`: "an invalid configuration
  blocks the form and is not saved".

### F24 Distribution linked to automatic releases

- Commits: `6ccfdb2`, `e053ac8`. Lockstep versions:
  [ADR 0004](../adr/0004-lockstep-distribution.md).
- Evidence: `tools/release/build-artifacts.php` packs the player bundles,
  the four npm packages and the Livewire/Filament archives;
  `tools/release/smoke-artifacts.sh` installs the tarballs with React and
  Vue and imports them; the CI `release-artifacts` job runs both;
  `tests/Release/DistributionTest.php`: "reports an incomplete browser
  asset distribution", "installs the exact version from a Composer
  repository and builds a puzzle with it"; `tools/js-consumer` (React and
  Vue apps from packed tarballs, three browsers); `node
  tools/sync-player-bundle.mjs --check`.
- Blocker: the release workflow is not active (no `master`), there is no
  npm organization and no split Packagist repositories for the
  integrations; releases attach these files instead. Procedures:
  [RUNBOOK: Packagist and npm](../release/RUNBOOK.md#packagist-and-npm).

### F25 Accessible controls and mobile interactions

- Commits: `74fc66c`, `a9196db`, `f705df7`, `b05fd51`, `d4f11cd`.
- Tests: `packages/player/e2e/a11y.spec.ts`: "Web Component page: no axe
  violations" and one axe run per mode; `packages/player/e2e/plain.spec.ts`:
  "Italian labels and reduced motion", "swap: drag one tile onto another,
  or tap both"; `packages/player/e2e/configurator.spec.ts`: "touch drags
  move the crop frame", "keyboard only: change mode and difficulty, then
  move the crop"; `packages/player/test/player.test.ts`: "plays with the
  keyboard: arrows move a roving focus, Enter acts".
- Limit: dragging pieces with a finger on real devices and screen-reader
  output are manual checks ([PLAYER_API.md](../PLAYER_API.md#browser-support)).

### F26 Cross-adapter compatibility and upgrade contract

- Commits: `350879a`, `74fc66c`.
- Tests: `packages/player/test/adapter-contract.ts` (run for the binding,
  React and Vue): "mounts one player and reports ready once for the loaded
  definition", "switches puzzles on a new definition, but not on a new
  initialState alone"; `tools/js-consumer/e2e/adapters.spec.ts`: "both
  adapters reach the same state through the same moves".
- Contract: every component is released with the same version
  ([ADR 0004](../adr/0004-lockstep-distribution.md)); documents carry
  versioned schemas, and migrations are listed in
  [MIGRATION.md](../MIGRATION.md).
- Limit: Livewire and Filament are not part of the shared adapter
  contract test (they embed the Web Component), and no test loads a
  document from an older release into a newer adapter, because no older
  game document version exists yet.
