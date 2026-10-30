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


.memory { position: relative; display: grid; grid-template-columns: repeat(var(--ipp-cols), minmax(0, 1fr)); gap: var(--ipp-gap, 4px); width: 100%; direction: ltr; padding: 0.25rem; background: var(--ipp-surface); border-radius: var(--ipp-radius); user-select: none; -webkit-user-select: none; }
.card { aspect-ratio: var(--ipp-card-aspect); padding: 0.25rem; margin: 0; border: 1px solid var(--ipp-border); border-radius: var(--ipp-radius); background-color: var(--ipp-bg); background-repeat: no-repeat; color: var(--ipp-fg); font: inherit; cursor: pointer; overflow: hidden; display: grid; place-items: center; transition: background-color var(--ipp-duration) ease, opacity var(--ipp-duration) ease; touch-action: manipulation; }
.card[data-state='down'] { background-image: repeating-linear-gradient(45deg, var(--ipp-accent) 0 6px, transparent 6px 12px); background-color: var(--ipp-surface); }
.card[data-state='matched'] { cursor: default; opacity: 0.75; }
.card.is-correct { box-shadow: inset 0 0 0 3px var(--ipp-correct); opacity: 1; }
.card:focus-visible { outline: 3px solid var(--ipp-focus); outline-offset: 2px; }
.card:disabled { cursor: default; }
.card-text { font-size: clamp(0.75rem, 3cqi, 1.25rem); font-weight: 600; text-align: center; overflow-wrap: anywhere; }
.memory[data-loading='true'] .card[data-state='up'], .memory[data-loading='true'] .card[data-state='matched'] { background-image: none; }

.nonogram-tools { display: flex; gap: 0.25rem; }
.nonogram { --ipp-clue: minmax(2.5rem, auto); display: grid; grid-template-columns: var(--ipp-clue) repeat(var(--ipp-cols), minmax(0, 1fr)); width: 100%; direction: ltr; background: var(--ipp-surface); border-radius: var(--ipp-radius); padding: 0.25rem; user-select: none; -webkit-user-select: none; font-variant-numeric: tabular-nums; }
.nonogram-row, .nonogram-body { display: contents; }
.nonogram-corner { grid-column: 1; }
.clue { display: flex; gap: 0.25em; font-size: clamp(0.625rem, 2.5cqi, 0.875rem); line-height: 1.2; color: var(--ipp-fg); }
.clue-col { flex-direction: column; justify-content: flex-end; align-items: center; padding-bottom: 0.25rem; }
.clue-row { justify-content: flex-end; align-items: center; padding-right: 0.375rem; white-space: nowrap; }
.clue.is-done { color: var(--ipp-muted); text-decoration: line-through; }
.nonogram-cell { aspect-ratio: 1; position: relative; }
.cell { position: absolute; inset: 0; width: 100%; height: 100%; padding: 0; margin: 0; border: 1px solid var(--ipp-border); border-radius: 0; background: var(--ipp-bg); cursor: pointer; touch-action: manipulation; }
.cell[data-state='filled'] { background: var(--ipp-fg); }
.cell[data-state='crossed']::before, .cell[data-state='crossed']::after { content: ''; position: absolute; inset: 50% 20% auto; height: 2px; background: var(--ipp-muted); transform: rotate(45deg); }
.cell[data-state='crossed']::after { transform: rotate(-45deg); }
.cell:focus-visible { outline: 3px solid var(--ipp-focus); outline-offset: -3px; z-index: 3; }
.cell:disabled { cursor: default; }
.cell.is-hint { box-shadow: inset 0 0 0 3px var(--ipp-hint); animation: ipp-hint 1s ease 2; }
.nonogram-picture { grid-column: 2 / -1; grid-row: 2 / span var(--ipp-rows); align-self: stretch; z-index: 4; background-repeat: no-repeat; pointer-events: none; }
.nonogram-picture[hidden] { display: none; }
.nonogram:not(.is-complete) .nonogram-picture { opacity: 0.85; }
.nonogram[data-loading='true'] .nonogram-picture { visibility: hidden; }

.tangram-tools { display: flex; gap: 0.25rem; flex-wrap: wrap; }
.tangram { display: block; width: 100%; height: auto; background: var(--ipp-tray); border-radius: var(--ipp-radius); user-select: none; -webkit-user-select: none; touch-action: none; }
.tangram .target path { fill: var(--ipp-surface); stroke: var(--ipp-border); stroke-width: 1; vector-effect: non-scaling-stroke; }
.tangram .preview { opacity: 0.4; pointer-events: none; }
.tangram .ghost { fill: none; stroke: var(--ipp-hint); stroke-width: 3; stroke-dasharray: 6 4; vector-effect: non-scaling-stroke; pointer-events: none; }
.tangram .piece { cursor: grab; outline: none; }
.tangram .piece.is-dragging { cursor: grabbing; }
.tangram .piece .outline { fill: transparent; stroke: var(--ipp-border); stroke-width: 1; vector-effect: non-scaling-stroke; }
.tangram .piece:focus-visible .outline, .tangram .piece.is-selected:focus .outline { stroke: var(--ipp-focus); stroke-width: 3; }
.tangram .piece.is-placed.highlight .outline { stroke: var(--ipp-correct); stroke-width: 2; }
.tangram .piece.is-hint .outline { stroke: var(--ipp-hint); stroke-width: 3; animation: ipp-hint 1s ease 2; }
.tangram[data-loading='true'] .piece image { visibility: hidden; }

@keyframes ipp-hint { 50% { opacity: 0.5; } }
@media (prefers-reduced-motion: reduce) {
  :host(:not([data-motion='full'])) .tile, :host(:not([data-motion='full'])) .piece, :host(:not([data-motion='full'])) .card { transition: none; }
  :host(:not([data-motion='full'])) .is-hint, :host(:not([data-motion='full'])) .is-hint .outline { animation: none; }
}
:host([data-motion='reduce']) .tile, :host([data-motion='reduce']) .piece, :host([data-motion='reduce']) .card { transition: none; }
:host([data-motion='reduce']) .is-hint, :host([data-motion='reduce']) .is-hint .outline { animation: none; }
@media (forced-colors: active) {
  .tile:focus-visible, button:focus-visible { outline-color: Highlight; }
  .cell[data-state='filled'] { background: CanvasText; }
  .card[data-state='down'] { background-image: none; background-color: GrayText; }
  .cell[data-state='crossed']::before, .cell[data-state='crossed']::after { background: GrayText; }
  .piece:focus-visible .outline { stroke: Highlight; }
  .tangram .target path { fill: Canvas; stroke: CanvasText; }
}
`;
