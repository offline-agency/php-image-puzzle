# ADR 0001: Versioned game definitions with one rules contract in PHP and TypeScript

Status: accepted (2026-10-25)

## Context

The library grew from slicing images into a family of playable puzzles that
must work in plain JavaScript, a Web Component, Livewire/Filament, Vue and
React. Forking gameplay per frontend would let behaviour drift, and the
browser must not regenerate geometry PHP already computed.

## Decision

- PHP generates an immutable, versioned **definition** (geometry, rules,
  initial scramble) whose ID is a digest of its content. Player **state** is a
  separate versioned document bound to that ID.
- Rules are pure functions over JSON-shaped boards and actions, implemented
  once in PHP (`src/Game/Rules`) and once in the TypeScript engine. Every
  frontend delegates to the TypeScript engine.
- Conformance is enforced by fixtures generated from PHP (`conformance/`):
  PRNG vectors, scrambles per seed, legal action lists, transitions, hints,
  completion and import accept/reject decisions.
- Randomness uses a small portable generator (FNV-1a seed mixing plus
  mulberry32) with exact integer range reduction, so both languages produce
  identical scrambles.

## Consequences

- Adding a mode means a PHP rules class, a TypeScript rules module, schema
  entries and regenerated fixtures; the fixture test fails until both agree.
- Changing generation for identical inputs requires bumping
  `GameDefinition::GENERATOR` (and the preset version for preset changes).
- Server-side validation is optional but available with the same semantics.
