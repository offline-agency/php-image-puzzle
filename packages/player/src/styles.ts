import { themeTokens } from './theme.js';

/**
 * Shadow-root stylesheet. Everything is scoped to the player's shadow tree;
 * hosts customise it through the --ipp-* custom properties and ::part().
 */
export const styles: string = `${themeTokens}
:host {
  display: block;
  color: var(--ipp-fg);
  font-family: var(--ipp-font);
  container-type: inline-size;
}
:host([hidden]) { display: none; }
* { box-sizing: border-box; }
.root { display: grid; gap: 0.5rem; background: var(--ipp-bg); }
.toolbar { display: flex; flex-wrap: wrap; gap: 0.25rem; }
.toolbar[hidden] { display: none; }
button.control {
  font: inherit;
  font-size: 0.875rem;
  min-height: 2.75rem;
  min-width: 2.75rem;
  padding: 0.25rem 0.75rem;
  border: 1px solid var(--ipp-border);
  border-radius: var(--ipp-radius);
  background: var(--ipp-surface);
  color: var(--ipp-fg);
  cursor: pointer;
}
button.control[aria-pressed='true'] { background: var(--ipp-accent); color: var(--ipp-accent-fg); border-color: var(--ipp-accent); }
button.control:disabled { opacity: 0.5; cursor: not-allowed; }
button:focus-visible { outline: 3px solid var(--ipp-focus); outline-offset: 2px; }
.status { display: flex; flex-wrap: wrap; gap: 0.25rem 1rem; color: var(--ipp-muted); font-size: 0.875rem; font-variant-numeric: tabular-nums; }
.message { margin: 0; padding: 1rem; border: 1px dashed var(--ipp-border); border-radius: var(--ipp-radius); }
.message[data-kind='error'] { border-style: solid; border-color: var(--ipp-error); color: var(--ipp-error); }
.sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }

.board { position: relative; width: 100%; direction: ltr; background: var(--ipp-surface); border-radius: var(--ipp-radius); overflow: hidden; touch-action: manipulation; user-select: none; -webkit-user-select: none; }
.board[data-loading='true'] .tile, .board[data-loading='true'] .piece { visibility: hidden; }
.tile {
  position: absolute;
  padding: 0;
  margin: 0;
  border: 0;
  border-radius: 0;
  background-color: var(--ipp-surface);
  background-repeat: no-repeat;
  box-shadow: inset 0 0 0 calc(var(--ipp-gap) / 2) var(--ipp-bg);
  cursor: pointer;
  transition: left var(--ipp-duration) ease, top var(--ipp-duration) ease, transform var(--ipp-duration) ease;
  touch-action: none;
}
.tile:focus-visible { outline: 3px solid var(--ipp-focus); outline-offset: -3px; z-index: 3; }
.tile[aria-pressed='true'] { box-shadow: inset 0 0 0 4px var(--ipp-accent); z-index: 2; }
.tile.is-correct::after { content: ''; position: absolute; inset: auto 4px 4px auto; width: 10px; height: 10px; border-radius: 50%; background: var(--ipp-correct); box-shadow: 0 0 0 2px var(--ipp-bg); }
.tile.is-hint, .piece.is-hint .outline { animation: ipp-hint 1s ease 2; }
.tile.is-hint { box-shadow: inset 0 0 0 4px var(--ipp-hint); }
.tile.is-dragging { transition: none; z-index: 4; opacity: 0.9; }
.tile.is-target { box-shadow: inset 0 0 0 4px var(--ipp-accent); }
.number { position: absolute; inset: 4px auto auto 4px; padding: 0 0.3em; border-radius: 3px; background: rgb(0 0 0 / 0.6); color: #fff; font-size: 0.75rem; line-height: 1.4; pointer-events: none; }
.marker { position: absolute; background: var(--ipp-accent); pointer-events: none; z-index: 5; }
.preview { position: absolute; background-repeat: no-repeat; pointer-events: none; z-index: 6; opacity: 0.85; }
.preview[hidden] { display: none; }

.viewport { position: relative; width: 100%; overflow: auto; border-radius: var(--ipp-radius); background: var(--ipp-tray); overscroll-behavior: contain; }
.stage { position: relative; background: var(--ipp-tray); }
.stage .board-area { position: absolute; background: var(--ipp-surface); outline: 1px solid var(--ipp-border); }
.stage .guides { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
.stage .guides path { fill: none; stroke: var(--ipp-border); stroke-width: 1; vector-effect: non-scaling-stroke; }
.piece { position: absolute; padding: 0; margin: 0; border: 0; background: none; pointer-events: none; transition: left var(--ipp-duration) ease, top var(--ipp-duration) ease; }
.piece:focus-visible { outline: none; }
.piece img { display: block; width: 100%; height: 100%; pointer-events: none; -webkit-user-drag: none; }
.piece .piece-image { display: block; width: 100%; height: 100%; pointer-events: none; }
.piece svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }
.piece .outline { fill: transparent; stroke: none; pointer-events: all; cursor: grab; touch-action: none; vector-effect: non-scaling-stroke; }
.piece:focus-visible .outline { stroke: var(--ipp-focus); stroke-width: 3; }
.piece.is-dragging { transition: none; }
.piece.is-dragging .outline { cursor: grabbing; }
.piece.is-placed .outline { pointer-events: none; cursor: default; }
.piece.is-placed.highlight .outline { stroke: var(--ipp-correct); stroke-width: 1; }
.piece.is-hint .outline { stroke: var(--ipp-hint); stroke-width: 3; }

@keyframes ipp-hint { 50% { opacity: 0.5; } }
@media (prefers-reduced-motion: reduce) {
  :host(:not([data-motion='full'])) .tile, :host(:not([data-motion='full'])) .piece { transition: none; }
  :host(:not([data-motion='full'])) .is-hint, :host(:not([data-motion='full'])) .is-hint .outline { animation: none; }
}
:host([data-motion='reduce']) .tile, :host([data-motion='reduce']) .piece { transition: none; }
:host([data-motion='reduce']) .is-hint, :host([data-motion='reduce']) .is-hint .outline { animation: none; }
@media (forced-colors: active) {
  .tile:focus-visible, button:focus-visible { outline-color: Highlight; }
  .piece:focus-visible .outline { stroke: Highlight; }
}
`;
