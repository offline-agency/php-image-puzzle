# Gap matrix (G01–G28)

Defects of `1.0.2` (`b114a4a`) from the audit of 2026-10-24, with the
evidence that each one is fixed. New features are tracked separately in
[feature-matrix.md](feature-matrix.md); fresh command results are in
[verification.md](verification.md).

Every gap ends in one of three states:

- **verified fixed**: the defect was reproduced on `1.0.2` (or shown in its
  source), a test or check now covers it, and that check passed in this
  session;
- **verified already fixed**: covered by a check that passed, but no fix
  was needed in this change;
- **blocker**: what is left needs an action outside this repository; the
  missing step and its procedure are named.

"Reproduced" refers to [baseline-2026-10-25.txt](baseline-2026-10-25.txt)
(see [baseline.md](baseline.md)). Test names are quoted exactly. CI job keys
refer to `.github/workflows/ci.yml`; the `tests` job runs the whole root
Pest suite and `pest --group=process --fail-on-skipped`.

## Summary

| ID | Gap | Status |
| --- | --- | --- |
| G01 | Composer version/tag mismatch, missing Packagist releases | blocker (repository side verified fixed; post-release check needs the first automated release) |
| G02 | Undeclared EXIF dependency | verified fixed |
| G03 | Rounded masks erase square corners | verified fixed |
| G04 | Invalid splits, unchecked piece indices | verified fixed |
| G05 | No real 2D jigsaw | verified fixed |
| G06 | Identical sinus tab/socket, modified perimeter | verified fixed |
| G07 | Composite styles copy one-pixel strips | verified fixed |
| G08 | Child exceptions escape into the caller | verified fixed |
| G09 | Signal deaths, wait failures, invalid payloads report success | verified fixed |
| G10 | Parallel export ignores `overwrite=false` | verified fixed |
| G11 | Fork failure leaves started children running | verified fixed |
| G12 | Duplicate file names, manifest collision | verified fixed |
| G13 | Directory checks after `mkdir`, symlink redirection | verified fixed |
| G14 | Non-transactional writes, stale manifest | verified fixed |
| G15 | No resource budgets, full decode per worker | verified fixed |
| G16 | Concrete final injection, GD ownership, eager lists | verified fixed |
| G17 | Quality default 9, JPEG alpha, GIF/orientation | verified fixed |
| G18 | Schema ID drift, composite config, hook/schema disagreement | verified fixed |
| G19 | No single PHP/tooling/resolution policy | verified fixed |
| G20 | PHPStan level 5 on `src` only | verified fixed |
| G21 | Oracles, CI gates and process coverage | verified fixed |
| G22 | README banner, contributor file, stale changelog/links | verified fixed |
| G23 | Discovery metadata | blocker (Composer metadata verified fixed; GitHub description/topics/homepage need a maintainer) |
| G24 | Visual demonstration, CodeIgniter example | verified fixed |
| G25 | Agent commands, API recipes, evaluation method | verified fixed (method only; no evaluation run, by design) |
| G26 | Measurable adoption explanation | verified fixed (baseline and method only; no result claimed) |
| G27 | Safe automatic tag and release per merge | verified fixed for the required scenarios; **not active** until `master` exists |
| G28 | Reproducible development environment | blocker (Docker Desktop VM disk full on this machine; CI `docker` job not run fresh) |

## Details

### G01 Composer version/tag mismatch and missing Packagist releases

- Reproduced: `composer.json version='1.0.0'` on `1.0.2`. Packagist lists
  only `1.0.0` and `dev-main` (observed 2026-10-25), because the `1.0.1`
  and `1.0.2` tags declare `"version": "1.0.0"`.
- Fix: `ea32bd4` removed the `version` field; `6ccfdb2` added the release
  tooling, which refuses a `version` field in the root or integration
  `composer.json` and verifies Packagist after every release.
- Tests: `tests/Release/DistributionTest.php`: "refuses composer.json
  version fields that make Packagist skip the tag", "polls with bounded
  exponential backoff until the version appears", "installs the exact
  version from a Composer repository and builds a puzzle with it". Fresh
  archive install: `tools/consumer/run.sh` (`consumer` job).
- Check: `composer validate --strict`; `vendor/bin/pest tests/Release`.
- Blocker: the post-release metadata check
  (`php tools/release/verify-packagist.php --version=X.Y.Z --sha=SHA`) can
  only run after the first automated release. Old tags stay unlisted;
  see [RUNBOOK: current state](../release/RUNBOOK.md#current-state).

### G02 Undeclared EXIF dependency

- Reproduced: `exif_imagetype()` used without a fallback or requirement.
- Fix: `ea32bd4` (signature and `getimagesize` detection; `ext-exif` only
  suggested, for orientation), `6af8d8a`.
- Tests: `tests/ImageFormatsTest.php`: "EXIF orientation is applied
  exactly when requested", "signature and header mismatches are rejected";
  `tests/ImageLoaderTest.php`: "loadFromFile with file that exif_imagetype
  cannot determine throws Could not determine image type" (the name is a
  1.x leftover; the test covers the fallback).
- Check: CI `minimal-extensions` runs the suite without exif, pcntl and
  zip and asserts they are absent. Not run locally (this machine has all
  three; the Docker `minimal` image is blocked, see G28).

### G03 Rounded masks erase square corners

- Reproduced: 64/64 corner pixels transparent; radius >= min/2 disabled
  rounding.
- Fix: `ea32bd4` (pixel-centre arc mask, clamped radius, bounded cache).
- Tests: `tests/Regression/RoundedCornersRegressionTest.php`: "only pixels
  outside the arc are removed, on all four corners", "radius larger than
  half the shorter side is clamped, not disabled", "pre-existing
  transparency is preserved inside the arc", "mask cache is bounded".

### G04 Invalid splits and unchecked piece indices

- Reproduced: 5 rows on a 3 px image accepted; index 5 raised
  `Undefined array key`.
- Fix: `ea32bd4` (`Geometry\Grid`, `PuzzleGenerator`, `ResourceLimits`).
- Tests: `tests/Regression/GridValidationRegressionTest.php`: "more rows or
  cols than source pixels is rejected", "zero and negative grids are
  rejected", "invalid custom split strategy output is rejected", "requested
  piece indices are validated before lookup";
  `tests/Geometry/PolygonTopologyTest.php`: "indices outside a ragged row
  are rejected".

### G05 No real 2D jigsaw

- Reproduced: jigsaw changed 0 interior pixels; no tab padding.
- Fix: `ea32bd4` (shared-edge geometry, rasterizer, padded rendering),
  `c377d48` (other topologies).
- Tests: `tests/Geometry/JigsawGeometryTest.php`: "every pixel centre
  belongs to exactly one piece (winding-number oracle)", "shared boundaries
  are exact complements and the board perimeter is flat", "exact mode
  reconstructs every source pixel bit-exactly with exactly one owner";
  `tests/PieceTest.php`: "toGdImage renders the rendered bounds of the
  shape".

### G06 Identical sinus tab/socket; modified perimeter

- Reproduced: a 1x1 board lost 19 perimeter pixels; identical branches.
- Fix: `ea32bd4` (`Geometry\Profile\SinusProfile`).
- Tests: `tests/Geometry/JigsawGeometryTest.php`, dataset `styles()`
  including `sinus`: "areas sum to the board and tabs protrude while
  sockets indent", "shared boundaries are exact complements and the board
  perimeter is flat", "a single-piece board is the untouched rectangle".

### G07 Composite styles copy one-pixel strips

- Reproduced: `CompositeEdgeStyle::copyEdge` copied 1 px strips.
- Fix: `ea32bd4` (whole side profiles), `67d384b`.
- Tests: `tests/CompositeEdgeStyleTest.php`: "composite applies whole side
  geometry: jigsaw horizontal and sinus vertical boundaries", "opposite
  sides must agree because they describe the same shared boundaries", "flat
  composite equals rectangular slicing".

### G08 Child exceptions escape into the caller

- Reproduced: the post-catch sentinel was written by 2 child PIDs.
- Fix: `ea32bd4` (worker envelopes, `terminateChild`), `5a4cfb0`.
- Tests: `tests/Process/ParallelWorkerTest.php`: "a throwing callback never
  returns into the caller in a child (PID sentinel)".

### G09 Signal deaths, wait failures, invalid payloads

- Reproduced: success reported with 0 pieces after SIGKILL of every worker.
- Fix: `ea32bd4`, `5a4cfb0`, `a595e2f`; `266abd6` for a failing `waitpid`
  in the exporter and the batch runner.
- Tests: `tests/Process/ParallelWorkerTest.php`: "a worker killed by a
  signal fails the export and publishes no manifest", "a nonzero worker
  exit without envelope fails the export", "invalid worker envelopes are
  failures", "an interrupted wait is retried and the export succeeds", "a
  failed wait fails the export, stops the worker and leaves no orphan
  writes"; `tests/Batch/BatchRunnerTest.php`: "fails the running items
  instead of spinning when waiting for workers fails" (failed before
  `266abd6`).

### G10 Parallel export ignores `overwrite=false`

- Reproduced: the sentinel was overwritten.
- Fix: `ea32bd4`, `5a4cfb0`.
- Tests: `tests/Process/ParallelWorkerTest.php`: "parallel export honours
  overwrite=false exactly like sequential export";
  `tests/Export/ExportSafetyTest.php`: "overwrite=false keeps valid
  existing pieces and rewrites the manifest", "overwrite=false rejects
  stale files with other dimensions before writing".

### G11 Fork failure leaves started children running

- Not in the phase-0 run. Shown in the `1.0.2` source:
  `b114a4a:src/Exporter.php` lines 182–188 throw on `pcntl_fork() === -1`
  without signalling or reaping the workers already started.
- Fix: `ea32bd4` (`killAndReap`), `a595e2f` (requires `posix_kill`).
- Tests: `tests/Process/ParallelWorkerTest.php`: "fork failure after one
  worker started terminates and reaps it with no orphan writes", "repeated
  parallel jobs leave no zombies and no steadily growing memory".

### G12 Duplicate file names, manifest collision

- Reproduced: 4 pieces mapped to 2 files; a piece named like the manifest
  was overwritten.
- Fix: `ea32bd4` (`Export\OutputPlan` preflight), `5a4cfb0`.
- Tests: `tests/Export/ExportSafetyTest.php`: "duplicate destinations are
  rejected with no writes", "case-insensitive duplicates and manifest
  collisions are rejected", "NUL and control characters in names are
  rejected".

### G13 Directory checks after `mkdir`, symlinks

- Reproduced: a rejected outside-base directory was still created.
- Fix: `ea32bd4` (`Export\PathGuard` before any `mkdir`), `5a4cfb0`.
- Tests: `tests/Export/ExportSafetyTest.php`: "outside-base targets are
  rejected before any directory is created", "symlinked directories
  pointing outside the base are rejected", "pre-existing destination
  symlinks are rejected, never followed";
  `tests/Export/VectorExportTest.php`: "rejects a symlink that resolves
  outside the directory".

### G14 Non-transactional writes, stale manifest

- Reproduced: new pieces next to a stale manifest after a failure.
- Fix: `ea32bd4` (staging, verification, per-file atomic publication,
  generation mode), `5a4cfb0`.
- Tests: `tests/Export/ExportSafetyTest.php`: "a failure while staging
  leaves the previous export untouched", "a manifest failure leaves the
  previous export untouched", "a publication failure leaves no manifest
  (documented incomplete state) and a re-run recovers", "generation mode
  publishes an all-or-nothing view through current.json".
- Semantics: the default mode never leaves a manifest that describes other
  files; a failure during publication can leave pieces without a manifest,
  which a re-run repairs. Generation mode is all-or-nothing. Both are
  documented in [OPERATIONS.md](../OPERATIONS.md) and on
  `Export\ExportSession`.

### G15 No resource budgets; full decode per worker

- Reproduced: `ImageLoader` had no byte, dimension or pixel budget.
- Fix: `ea32bd4` (`ResourceLimits` checked before decoding), `d1c265a`
  (per-worker memory reporting, `tools/benchmark.php`), `4a62285` (the
  parent decodes once before forking; workers share the image
  copy-on-write).
- Tests: `tests/Regression/GridValidationRegressionTest.php`: "piece budget
  is enforced before generation", "pixel budget is enforced on supplied
  images", "budget rejection leaves no output";
  `tests/Process/ParallelWorkerTest.php`: "worker count is capped by the
  aggregate memory budget", "each worker reports its own memory usage",
  "the source is decoded once, before the workers start, and shared by all
  of them" (failed before `4a62285`); `tests/Cli/ApplicationTest.php`:
  "exits with 5 when a resource limit is exceeded".
- Measurement: `composer benchmark`, before and after `4a62285`, in
  [verification.md](verification.md#performance-and-memory).

### G16 Concrete final injection, GD ownership, eager lists

- Reproduced: `PuzzleGenerator` accepted final concretes only.
- Fix: `ea32bd4` (`ImageLoaderInterface`, `ExporterInterface`, generator
  based pieces, ownership contract on `PuzzleGenerator`).
- Tests: `tests/PuzzleGeneratorTest.php`: "an injected
  ImageLoaderInterface does the inspection and the decoding",
  "generateFromGd reads the caller image without modifying or destroying
  it" (added in `5a17398`; they pass on the `ea32bd4` code);
  `tests/PuzzleTest.php`: "puzzle from generator with injected exporter
  uses that exporter on export", "getPieces yields pieces in row-major
  order"; `tests/PuzzleGeneratorTest.php`: "generatePiecesFromGd returns
  only requested pieces".

### G17 Quality default 9, JPEG alpha, GIF/orientation

- Reproduced: JPEG kept quality 9/100.
- Fix: `ea32bd4` (per-format quality, `Export\ImageEncoder`), `6af8d8a`,
  `d02c984`.
- Tests: `tests/ExportOptionsTest.php`: "quality boundaries are format
  specific"; `tests/Export/ExportSafetyTest.php`: "JPEG export flattens
  transparency onto the configured background";
  `tests/ImageFormatsTest.php`: "animated GIFs are rejected unless the
  first frame is explicitly accepted", "with orientation applied, the grid
  is validated against the displayed size".

### G18 Schema ID drift, composite config, hook/schema disagreement

- Reproduced: schema `$id` pointed at another repository.
- Fix: `67d384b`, `9d19add`, `ff555bf`.
- Tests: `tests/EdgeStyleFactoryTest.php`: "fromArray composite rejects
  contradictory and raster-only sides"; `tests/ExportTest.php`: "export
  onManifestBuild can extend the manifest through extensions only",
  "exportParallel runs onPieceBeforeSave in the workers and the other hooks
  in the parent"; `tests/Config/PuzzleConfigTest.php`: "the schema
  validator rejects documents that violate the manifest schema".
- Check: `php tools/validate-schemas.php` validates every schema, checks
  each `$id` against this repository and validates the examples.

### G19 One PHP, tooling and resolution policy

- Fix: `ea32bd4` (`php: ~8.4.0 || ~8.5.0`, lock file, aligned tools),
  `c6d5a17` (matrix), `5489af3`.
- Policy: PHP 8.4 and 8.5, tested with locked, highest and lowest
  dependencies; integrations follow the core version
  ([ADR 0004](../adr/0004-lockstep-distribution.md)).
- Checks: CI `tests` (8.4/8.5 x locked/highest/lowest), `consumer`
  (`--prefer-lowest` install in a fresh project), `integrations`. This
  session ran PHP 8.5 locally; PHP 8.4 runs in CI and in Docker (G28).
- Limit: no job pins the oldest patch release (8.4.0).

### G20 PHPStan level 5 on `src` only

- Fix: `7fd41ec`. `phpstan.neon.dist`: level max, strict rules, `src`,
  `tests`, `tools`, `examples`, `bin`; no baseline, no `ignoreErrors`. The
  integrations use the same policy.
- Check: `vendor/bin/phpstan analyse --memory-limit=2G` (core) and the
  integrations' `vendor/bin/phpstan analyse`.

### G21 Oracles, CI gates, process coverage

- Fix: `5a4cfb0`, `c6d5a17`, `a595e2f`, `ea32bd4`.
- Tests: `tests/Process/ParallelWorkerTest.php`: "parallel output is
  byte-identical to sequential output"; `tests/Export/SequentialFallbackTest.php`:
  "parallel export without process control is sequential and
  byte-identical"; geometry oracles (G05).
- CI: `gate` requires every job; `tests` fails when the process group is
  skipped; `coverage` (minimum 85 %) and `mutation`. Coverage and mutation
  need pcov/the Docker image and were not run fresh here (G28).

### G22 README banner, contributor file, stale links

- Fix: `be135d2`; link checker added with this change.
- Tests: `tests/Docs/CodeBlocksTest.php`: "keeps every documented PHP block
  valid against the shipped API"; `tests/Docs/SnippetsTest.php`: "runs the
  documented snippets"; `tests/Docs/LinksTest.php`: "resolves every
  repository link" (external sites are not fetched).
- Note: the CI badge reads `?branch=main` until the follow-up pull request
  of the activation ([RUNBOOK](../release/RUNBOOK.md#6-follow-up-pull-request)).

### G23 Discovery metadata

- Fixed in the repository: `composer.json` description, keywords,
  homepage and support links (`be135d2`); `composer validate --strict`.
- Blocker: the GitHub description, topics and homepage are repository
  settings. Command:
  [RUNBOOK: repository metadata](../release/RUNBOOK.md#repository-metadata).

### G24 Visual demonstration and CodeIgniter example

- Fix: `b18b409`, `ea9b80a`, `ff1bd08`.
- Tests: `tests/Docs/ShowcaseTest.php`: "reassembles the source exactly and
  matches the committed images"; `tests/Docs/ExampleScriptsTest.php`:
  "builds a demo site for every mode with examples/demo/build.php";
  `packages/player/e2e/demo.spec.ts`: "lists every mode and opens the first
  by default"; `tools/codeigniter/run.sh` (fresh CodeIgniter 4 project).

### G25 Agent commands, API recipes, evaluation method

- Fix: `be135d2` (`AGENTS.md`, `llms.txt`, `docs/API.md`, checked
  snippets), `84bcfc8` (evaluation method).
- Tests: `tests/Docs/SnippetsTest.php`, `tests/Docs/CodeBlocksTest.php`.
- Limit: the agent-recommendation evaluation has a method, prompts and an
  empty log ([agent evaluation](../adoption/agent-evaluation/README.md));
  no run is claimed.

### G26 Measurable adoption explanation

- Fix: `84bcfc8`: dated baseline with sources, hypotheses, the experiment
  and a checkpoint template ([docs/adoption](../adoption/README.md)).
- Limit: no causal result is claimed; the experiment starts after the
  first release and the GitHub metadata (G23).

### G27 Safe automatic tag and release per merge

- Fix: `6ccfdb2`, `e053ac8` (adversarial review).
- Tests: `tests/Release/ScenariosTest.php` (30 scenarios against a fake
  GitHub API and temporary Git repositories), `VersioningTest.php`,
  `DistributionTest.php`, `WorkflowTest.php`; `composer release:test`;
  `actionlint`.
- Not active: the workflow targets `master`, and the repository has only
  `main`. Activation, protection rules and recovery:
  [RUNBOOK](../release/RUNBOOK.md#activation-from-main-to-master).

### G28 Reproducible development environment

- Fix: `5489af3` (`Dockerfile`, `compose.yaml` with `php85`, `php84`,
  `minimal` and `node`), `266abd6` (build with host user IDs that already
  exist in Debian, such as macOS group 20).
- Earlier in this session the `php84` image built and ran the suite; the
  run was not clean because of volume ownership, which `266abd6` and
  removing this project's stale volumes addressed.
- Blocker: the Docker Desktop VM disk on this machine is full (70.3 GB,
  0 bytes available; 30 GB of reclaimable volumes that belong to other
  projects). In the final run the images built from cache, but `composer
  install` inside them failed ("less than 100MiB of free space"), so the
  suites could not run. Procedure: free
  space (`docker system df`, then remove what you no longer need), then
  `docker compose build && docker compose run --rm php84 sh -c 'composer
  install && vendor/bin/pest'` and the same for `minimal`. The CI `docker`
  job runs the same commands.
