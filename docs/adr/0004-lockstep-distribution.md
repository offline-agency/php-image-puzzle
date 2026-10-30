# ADR 0004: Lockstep versions and committed browser bundles

Status: accepted (2026-10-25)

## Context

One repository ships a PHP package, a TypeScript engine, a player with a Web
Component, React and Vue adapters, and Livewire and Filament packages, all
reading the same versioned schemas. Composer installs PHP packages from Git
tags, so files built in CI never reach a Composer user unless they are in
the tagged tree. npm and the Packagist adapter repositories need registry
ownership that this repository does not have yet.

## Decision

- **One version for everything.** Each release `X.Y.Z` applies to every
  component. npm tarballs are packed with `X.Y.Z` and internal dependencies
  pinned to exactly `X.Y.Z`. The workspaces keep `0.0.0-dev` in source, so
  no commit edits versions. Compatibility is "same version". There is no
  matrix of independent ranges.
- **Prebuilt players are committed.** `resources/dist` (IIFE, used by ZIP
  bundles) and `integrations/livewire/resources/dist` (ES module) hold the
  built player. CI rebuilds and compares them byte for byte. The release
  refuses to build artifacts if they differ, or if the IIFE is missing from
  the commit's archive. A tagged commit is never rewritten.
- **The GitHub Release is the distribution point** for artifacts that have
  no registry yet: browser bundles, npm tarballs and integration ZIPs, with
  a manifest and `SHA256SUMS`. Registry publication (npm, split Packagist
  repositories) is a documented, separate maintainer step.
- **`composer.json` has no `version` field**, so Packagist takes versions
  from tags. A `version` field made Packagist skip `1.0.1` and `1.0.2`. The
  artifact build refuses one.

## Consequences

- A change to one adapter releases all components at a new version, even
  though most did not change. This is acceptable while the packages ship
  together, and the release notes list the changed commits.
- Contributors must run `npm run build && npm run sync:bundles` and commit
  the result when the player changes. CI says so when they forget.
- Users of the Livewire and Filament packages install from the repository
  until split repositories exist
  ([RUNBOOK](../release/RUNBOOK.md#packagist-and-npm)).
