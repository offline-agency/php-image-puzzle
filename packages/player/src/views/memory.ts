import { memoryPair } from '@offline-agency/image-puzzle-engine';
import type { Board, MemoryBoard, Tile } from '@offline-agency/image-puzzle-engine';
import { el } from '../dom.js';
import { format } from '../messages.js';
import { backgroundFor, loadImage } from './media.js';
import type { ModeView, ViewContext } from './types.js';

/** How long a mismatched pair stays visible. Visual only: the state keeps it face up until the next flip. */
export const MISMATCH_MS = 1000;

/**
 * DOM view of memory: one button per card position with roving focus.
 * Enter/Space or a click flips a card. A face-down card carries neither its
 * picture nor its label in the DOM, so hidden cards cannot be read from the
 * page; face-up picture cards are named by their pair number for screen
 * readers, label cards by their text.
 */
export function createMemoryView(ctx: ViewContext): ModeView {
  const { doc, definition } = ctx;
  const { rows, cols, source } = definition;
  const tiles = new Map(definition.tiles.map((tile) => [tile.id, tile]));
  const imageUrl = ctx.asset(definition.media.image);
  const first = definition.tiles[0];
  const aspect = first === undefined ? '1' : `${first.rect.width} / ${first.rect.height}`;

  const element = el(doc, 'div', { class: 'board-wrap' });
  const instructions = el(doc, 'p', { class: 'sr-only', id: `ipp-instructions-${Math.random().toString(36).slice(2, 10)}` });
  const board = el(doc, 'div', {
    class: 'memory',
    part: 'board',
    role: 'group',
    'aria-describedby': instructions.id,
    'data-kind': 'memory',
    'data-loading': 'true',
  });
  board.style.setProperty('--ipp-cols', String(cols));
  board.style.setProperty('--ipp-card-aspect', aspect);
  const buttons: HTMLButtonElement[] = [];
  for (let i = 0; i < rows * cols; i++) {
    const button = el(doc, 'button', { type: 'button', class: 'card', part: 'tile', tabindex: '-1', 'data-position': String(i) });
    buttons.push(button);
    board.append(button);
  }
  const preview = el(doc, 'div', { class: 'preview', part: 'preview', hidden: '' });
  Object.assign(preview.style, backgroundFor(imageUrl, definition.crop, source), { inset: '0' });
  board.append(preview);
  element.append(instructions, board);

  const image = loadImage(
    imageUrl,
    () => {
      board.setAttribute('data-loading', 'false');
    },
    () => {
      ctx.assetError(imageUrl);
    },
  );

  let state = ctx.engine.exportState().board as MemoryBoard;
  let focus = 0;
  // A mismatch is shown until this timer turns it face down on screen.
  let concealed = false;
  let mismatchTimer: ReturnType<typeof setTimeout> | null = null;

  const pairNumber = (tile: Tile): number => Number(memoryPair(tile.id).slice(1)) + 1;

  function faceText(tile: Tile): string {
    const m = ctx.messages();
    return tile.attributes['face'] === 'text' ? String(tile.attributes['text']) : format(m.memoryPicture, { n: pairNumber(tile) });
  }

  function render(): void {
    const m = ctx.messages();
    instructions.textContent = m.instructions['memory'] ?? '';
    board.setAttribute('aria-label', m.label);
    const complete = ctx.engine.status === 'complete';
    state.cards.forEach((id, position) => {
      const button = buttons[position];
      const tile = tiles.get(id);
      if (button === undefined || tile === undefined) return;
      const matched = state.matched.includes(id);
      const up = matched || (state.revealed.includes(id) && !(concealed && state.revealed.length === 2));
      button.replaceChildren();
      button.removeAttribute('style');
      button.dataset['state'] = matched ? 'matched' : up ? 'up' : 'down';
      button.classList.toggle('is-correct', matched && definition.assistance.highlightCorrect);
      if (up) {
        if (tile.attributes['face'] === 'text') {
          button.append(el(doc, 'span', { class: 'card-text', 'aria-hidden': 'true' }, String(tile.attributes['text'])));
        } else {
          Object.assign(button.style, backgroundFor(imageUrl, tile.rect, source));
        }
      }
      const n = position + 1;
      button.setAttribute(
        'aria-label',
        up ? format(matched ? m.memoryMatchedCard : m.memoryCard, { n, face: faceText(tile) }) : format(m.memoryFaceDown, { n }),
      );
      button.tabIndex = position === focus ? 0 : -1;
      button.disabled = complete;
    });
  }

  function scheduleConceal(): void {
    if (mismatchTimer !== null) clearTimeout(mismatchTimer);
    mismatchTimer = null;
    concealed = false;
    if (state.revealed.length === 2) {
      mismatchTimer = setTimeout(() => {
        mismatchTimer = null;
        concealed = true;
        render();
      }, MISMATCH_MS);
    }
  }

  function flip(position: number): void {
    const id = state.cards[position];
    if (id === undefined || ctx.engine.status === 'complete') return;
    const m = ctx.messages();
    const matchedBefore = state.matched.length;
    if (!ctx.dispatch({ type: 'flip', card: id })) {
      ctx.announce(m.illegal);
      return;
    }
    const tile = tiles.get(id);
    if (tile === undefined) return;
    let text = format(m.memoryCard, { n: position + 1, face: faceText(tile) });
    if (state.matched.length > matchedBefore) text += ` ${m.memoryMatch}`;
    else if (state.revealed.length === 2) text += ` ${m.memoryMismatch}`;
    ctx.announce(text);
  }

  function moveFocus(position: number): void {
    focus = Math.max(0, Math.min(buttons.length - 1, position));
    render();
    buttons[focus]?.focus();
  }

  function positionFrom(event: Event): number | null {
    const button = (event.target as Element | null)?.closest('button.card');
    if (!(button instanceof HTMLElement) || !board.contains(button)) return null;
    const position = Number(button.dataset['position']);
    return Number.isInteger(position) ? position : null;
  }

  function onKeyDown(event: KeyboardEvent): void {
    const position = positionFrom(event);
    if (position === null) return;
    focus = position;
    const row = Math.floor(position / cols);
    const col = position % cols;
    const key = event.key;
    let next: number | null = null;
    if (key === 'ArrowUp' && row > 0) next = position - cols;
    else if (key === 'ArrowDown' && row < rows - 1) next = position + cols;
    else if (key === 'ArrowLeft' && col > 0) next = position - 1;
    else if (key === 'ArrowRight' && col < cols - 1) next = position + 1;
    else if (key === 'Home') next = event.ctrlKey ? 0 : row * cols;
    else if (key === 'End') next = event.ctrlKey ? buttons.length - 1 : row * cols + cols - 1;
    if (next !== null || key.startsWith('Arrow')) {
      event.preventDefault();
      if (next !== null) moveFocus(next);
      return;
    }
    if (key === 'Enter' || key === ' ') {
      event.preventDefault();
      flip(position);
    }
  }

  function onClick(event: MouseEvent): void {
    const position = positionFrom(event);
    // detail 0: the synthetic click of Enter/Space, already handled in onKeyDown.
    if (position === null || event.detail === 0) return;
    focus = position;
    flip(position);
  }

  board.addEventListener('keydown', onKeyDown);
  board.addEventListener('click', onClick);
  scheduleConceal();
  render();

  return {
    element,
    update(next: Board) {
      state = next as MemoryBoard;
      scheduleConceal();
      render();
    },
    showHint() {
      // Memory has no hints.
    },
    setPreview(visible) {
      preview.hidden = !visible;
    },
    focus() {
      buttons[focus]?.focus();
    },
    destroy() {
      if (mismatchTimer !== null) clearTimeout(mismatchTimer);
      image.cancel();
      board.removeEventListener('keydown', onKeyDown);
      board.removeEventListener('click', onClick);
      element.remove();
    },
  };
}
