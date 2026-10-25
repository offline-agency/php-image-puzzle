# Contributing

Thanks for helping. Issues and pull requests are welcome at
<https://github.com/offline-agency/php-image-puzzle>. For security problems,
follow [SECURITY.md](SECURITY.md) instead of opening an issue.

## Setup

Requirements: PHP 8.4 or 8.5 with gd (pcntl, zip and exif enable the full
suite), Composer 2, Node.js 24 and npm.

```bash
composer install
npm ci
npm run build
npx playwright install chromium firefox webkit   # browser tests only
```

Without a local toolchain, the Docker images run the PHP suite:

```bash
docker compose run --rm php85 composer install
docker compose run --rm php85 composer test
docker compose run --rm minimal composer test   # without exif, pcntl and zip
```

## Checks

Run what your change touches; CI runs all of it.

| Area | Command |
| --- | --- |
| PHP: validate, style, PHPStan level max, tests, schemas | `composer qa` |
| PHP/TypeScript rule conformance fixtures are current | `composer conformance -- --check` |
| TypeScript: lint and formatting, types, unit tests, build | `npm run lint && npm run typecheck && npm test && npm run build` |
| Player bundles copied into the PHP packages are current | `npm run sync:bundles` (then commit the copies) |
| Browser tests (five browser projects) | `npm run test:browser` |
| Livewire / Filament integrations | `composer -d integrations/livewire install && composer -d integrations/livewire test` (same for `filament`) |
| Installed package in a fresh project | `tools/consumer/run.sh` (needs a commit: it installs `git archive HEAD`) |
| CodeIgniter 4 example | `tools/codeigniter/run.sh` |
| Release automation scenarios | `composer release:test` |
| Mutation testing | `composer mutate` |

Regenerate generated files with the script that owns them instead of editing
them: `composer conformance` (conformance fixtures), `php
examples/player/generate.php` (example puzzles) and `php
examples/showcase.php` (README images).

## Conventions

- Code and maintained documentation are in English.
- A behaviour fix starts with a regression test that fails before the fix.
- Static analysis stays at PHPStan level max over `src`, `tests`, `tools`
  and `examples`, without baselines, ignored errors or suppression comments.
- The PHP core stays framework-independent; framework code belongs in
  `integrations/`. Game rules exist once per language (`src/Game/Rules`,
  `packages/engine/src/rules`) and are kept equal by the conformance
  fixtures; renderers and adapters never re-implement rules.
- Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/)
  (`feat:`, `fix:`, `docs:`, `test:`, `ci:`, `build:`, `chore:`; `!` or a
  `BREAKING CHANGE:` footer for breaking changes). Release notes and the next
  version are derived from them; see [docs/release/RUNBOOK.md](docs/release/RUNBOOK.md).
- Add an entry under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md) for
  user-visible changes.

## Pull requests

Keep a pull request to one topic, describe the behaviour change and how you
verified it, and make sure the CI gate is green. Maintainers merge; releases
are tagged automatically after merges to the release branch as described in
the runbook.
