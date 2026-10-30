# Verification

Fresh local run of 2026-10-25 on commit `a0c3dd1` (branch
`feat/remediation-modes-releases`), repeated on 2026-10-30 for the fixes
made after the first CI run of the pull request (Docker, coverage and
mutation rows, and the suites those fixes touch). Every command ran from a clean shell,
one after the other, with `CI=1`; each line gives the exit code. Historical
CI runs are not counted as evidence here. Status per defect and feature:
[gap-matrix.md](gap-matrix.md), [feature-matrix.md](feature-matrix.md).

## Environment

| Item | Version |
| --- | --- |
| OS | macOS (Darwin 24.4.0, arm64) |
| PHP | 8.5.12 NTS (Herd), extensions gd, exif, pcntl, zip; no pcov |
| GD | bundled, `GD_VERSION` 2.0.35 (2.1.0 compatible) |
| Composer | 2.10.2, dependencies from `composer.lock` |
| Node / npm | 25.0.0 / 11.6.4, dependencies from `package-lock.json` |
| Browsers | Playwright Chromium, Firefox, WebKit, plus Pixel 7 and iPhone 15 emulation |
| Renderers | `rsvg-convert`, `pdftoppm`, `qpdf` (rendered SVG and PDF checks) |
| Workflow lint | actionlint 1.7.12 |

PHP 8.4.27 ran in the Docker `php84` image (2026-10-30). CI runs the PHP
8.4/8.5 matrix with locked, highest and lowest dependencies.

## Results

| Check | Command | Exit | Result |
| --- | --- | --- | --- |
| Composer metadata | `composer validate --strict` | 0 | valid |
| Advisories | `composer audit` | 0 | none |
| Code style | `vendor/bin/pint --test` | 0 | clean |
| Static analysis | `vendor/bin/phpstan analyse --memory-limit=2G` (level max, no baseline) | 0 | no errors |
| Schemas and examples | `php tools/validate-schemas.php` | 0 | valid |
| PHP/TypeScript rules | `php tools/conformance.php --check` | 0 | fixtures current |
| PHP suite | `vendor/bin/pest --parallel` | 0 | 971 passed, 2 skipped, 1,615,364 assertions |
| Forked workers | `vendor/bin/pest --group=process --fail-on-skipped` | 0 | 20 passed, none skipped |
| Release scenarios | `php tools/release/run-scenarios.php` | 0 | 30 of 30 |
| Lint | `npx eslint .`, `npx prettier --check .` | 0, 0 | clean |
| Types | `npm run typecheck` | 0 | clean |
| TypeScript tests | `npm test` | 0 | 60 + 34 passed |
| Build | `npm run build` | 0 | built |
| Player copies in PHP packages | `node tools/sync-player-bundle.mjs --check` | 0 | current |
| Release artifacts | `build-artifacts.php --version=0.0.0` and `smoke-artifacts.sh` | 0 | built, checksums and installs verified |
| Browser suite | `npm run test:browser` | 0 | 393 passed, 27 skipped |
| Packed npm consumers | `tools/js-consumer/run.sh` | 0 | 9 passed (React, Vue, SSR; three browsers) |
| Composer consumer | `tools/consumer/run.sh` | 0 | install from `git archive HEAD`, smoke and CLI checks passed |
| Composer consumer, lowest | `tools/consumer/run.sh --prefer-lowest` | 0 | passed |
| CodeIgniter 4 recipe | `tools/codeigniter/run.sh` | 0 | passed |
| Livewire | `pint --test`, `phpstan`, `pest`, browser | 0, 0, 0, 0 | 24 passed; 15 browser tests passed |
| Filament | `pint --test`, `phpstan`, `pest`, browser | 0, 0, 0, 0 | 41 passed; 22 browser tests passed |
| Workflows | `actionlint -ignore 'for "concurrency" section'` | 0 | clean |
| Docker images | `docker compose build php84 php85 minimal` | 0 | built |
| Docker suite, PHP 8.4 | `docker compose run --rm php84 sh -c 'composer install && vendor/bin/pest --parallel'` | 0 | 963 passed, 10 skipped (SVG/PDF renderers and absent-extension tests; the image has no `rsvg-convert`, `pdftoppm`, `qpdf`) |
| Docker suite, minimal | `docker compose run --rm minimal sh -c 'composer install && vendor/bin/pest'` | 0 | 933 passed, 40 skipped; `php -m` lists none of exif, pcntl, zip |
| Coverage | `pest --coverage --min=85` in the `php85` image | 0 | 92.5 % lines |
| Mutation | `composer mutate` in the `php85` image | 0 | score 82.19 % (4,992 mutations tested, 1,082 uncovered; minimum 60 %) |
| Benchmark | `php tools/benchmark.php` | 0 | see below |

Skips:

- 2 PHP tests: "reports a missing zip extension (really absent)" and
  "constructor throws GdNotAvailableException when gd not loaded". Both
  need an extension to be absent, and this machine has zip and gd. The CI
  `minimal-extensions` job covers the case without zip.
- 27 browser tests are project variants that do not apply. Keyboard-only
  tests skip on the mobile projects, the touch test skips on the desktop
  projects, and finger drags of pieces cannot be synthesised by Playwright
  (manual matrix in [PLAYER_API.md](../PLAYER_API.md#browser-support)).
- The process group runs with `--fail-on-skipped`.

## Not verified here

- Branch coverage: pcov measures lines only.
- The release workflow on GitHub. It is not active (no `master` branch). No
  tag, release or merge was made.

## Performance and memory

`php tools/benchmark.php` (defaults: 48 pieces, median of 3 runs, memory
maxima). This was one machine and one session, so read the figures as
indications, not guarantees. "Sum worker RSS" adds up each worker's
maximum RSS; shared copy-on-write pages count once per worker.

| Case | Before `5a18b2d`: parent MiB / sum worker RSS | After: parent MiB / sum worker RSS |
| --- | --- | --- |
| 800x600, no edge, 4 workers | 8.0 / 52.0 | 10.0 / 37.7 |
| 800x600, jigsaw, 4 workers | 10.0 / 60.3 | 12.0 / 49.0 |
| 2000x1500, no edge, 4 workers | 12.0 / 119.4 | 28.6 / 63.9 |
| 2000x1500, jigsaw, 4 workers | 20.0 / 128.0 | 30.6 / 78.5 |

Wall time, 2000x1500 jigsaw: 0.687 s sequential, 0.211 s with 4 workers.
Parallel output is byte-identical to sequential output ("parallel output is
byte-identical to sequential output").

## Release automation

- Scenarios: 30 of 30, against a fake GitHub API and temporary Git
  repositories ([RUNBOOK: tests and tooling](../release/RUNBOOK.md#tests-and-tooling)).
- Predicted next release: the real classifier (`tools/release/src/Classifier.php`)
  ran on the 41 commits and 718 changed files between `1.0.2` and this
  branch.
  - Without a label it refuses. Published schemas were removed and the
    `composer.json` requirements changed, and both need a maintainer's
    label.
  - With `release:major` it gives **2.0.0** from 1.0.2, matching the
    breaking changes in [MIGRATION.md](../MIGRATION.md).
- Activation on `master`, permissions, branch and tag rules, check names
  and recovery: [RUNBOOK](../release/RUNBOOK.md).

## Registry state

Observed on 2026-10-25:

- Packagist lists `offline-agency/image-puzzle` `1.0.0` and `dev-main`
  only; `1.0.1` and `1.0.2` are missing because their tags declare
  `"version": "1.0.0"`.
- The npm packages and the Livewire/Filament packages are not published.
  Releases attach them as files, which the `release-artifacts` check builds
  and installs.
