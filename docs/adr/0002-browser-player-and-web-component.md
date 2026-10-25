# ADR 0002: One shadow-DOM player shared by the plain-JS API and the Web Component

Status: accepted (2026-10-25)

## Context

The project needs a plain JavaScript player, a Web Component, and later
Livewire/Filament, Vue and React adapters. Each must behave the same way, be
usable without a bundler, survive host-page CSS, and be importable during
server-side rendering.

## Decision

- One controller, `createPlayer(target, options)`, owns one engine and one
  DOM renderer per instance. Every adapter wraps it. The Web Component passes
  its own shadow root; the plain API creates a host element with an open
  shadow root, so both paths render identical markup and styles.
- Rendering uses DOM, not canvas. Grid modes use one `<button>` per tile.
  Jigsaw pieces are PNGs, and an SVG path is their hit area and focus ring.
  This gives native focus, accessible names and forced-colors support without
  a parallel accessibility tree.
- Customisation goes through `--ipp-*` custom properties and `::part()` names,
  not global CSS.
- The custom element class is created inside `defineImagePuzzlePlayer()`, so
  no module touches `HTMLElement`, `window` or `document` when it is
  evaluated. Registration is idempotent.
- Element events are `puzzle-<engine event>` CustomEvents (`bubbles`,
  `composed`) carrying the engine payload unchanged. The prefix avoids
  colliding with native `error` and `progress` events, which bubble to
  `window` handlers.
- `definition` (property) takes precedence over `src` (attribute).
  Configuration (`options`, `persist`, `motion`, `no-controls`) is read when
  the player is created. A detached element is suspended (clock paused,
  persistence flushed) rather than destroyed, so DOM moves and re-attachment
  keep the game. `dispose()` is the explicit release.
- Distribution has two forms. A per-module ESM build with declarations (tsc)
  serves bundlers. Two single-file builds that include the engine (Vite: ES
  module and IIFE) serve no-bundler pages.

## Consequences

- Framework adapters stay thin: they forward options and map the six events
  to idiomatic names.
- Rectangular hit areas are avoided for jigsaw pieces, but dragging connected
  groups is not supported yet; each drop is one legal `place` action.
- Touch drag on real devices and screen-reader output stay manual release
  checks (see docs/PLAYER_API.md), because the browser automation available
  in CI cannot drive them.
