import type { Action, Board, Tile } from '@offline-agency/image-puzzle-engine';
import { el, pct } from '../dom.js';
import { format } from '../messages.js';
import { backgroundFor, loadImage } from './media.js';
import type { ModeView, ViewContext } from './types.js';

const DRAG_THRESHOLD = 6;

interface Layout {
  /** Tile id at each board position (null = sliding gap). */
  readonly cells: readonly (string | null)[];
  /** Displayed clockwise orientation per tile id. */
  readonly orientation: ReadonlyMap<string, number>;
}

function layoutOf(kind: string, tiles: readonly Tile[], board: Board): Layout {
  const orientation = new Map<string, number>();
  if (kind === 'rotation' && 'orientations' in board) {
    tiles.forEach((tile, i) => orientation.set(tile.id, board.orientations[i] ?? 0));
    return { cells: tiles.map((tile) => tile.id), orientation };
  }
  if (kind === 'strips' && 'order' in board) {
    return { cells: board.order, orientation };
  }
  if ('cells' in board && typeof board.cells !== 'string') {
    return { cells: board.cells, orientation };
  }
  throw new Error(`Board does not match mode ${kind}`);
}

/**
 * DOM view of the grid modes (sliding, swap, rotation, strips): one button
 * per tile on a board of equal cells, roving focus with the arrow keys and
 * pointer/keyboard alternatives for every action.
 */
export function createGridView(ctx: ViewContext): ModeView {
  const { doc, definition } = ctx;
  const { kind, rows, cols, crop, source } = definition;
  const tilesById = new Map(definition.tiles.map((tile) => [tile.id, tile]));
  const imageUrl = ctx.asset(definition.media.image);

  const instructionsId = `ipp-instructions-${Math.random().toString(36).slice(2, 10)}`;
  const element = el(doc, 'div', { class: 'board-wrap' });
  const instructions = el(doc, 'p', { class: 'sr-only', id: instructionsId }, ctx.messages().instructions[kind] ?? '');
  const board = el(doc, 'div', {
    class: 'board',
    part: 'board',
    role: 'group',
    'aria-label': ctx.messages().label,
    'aria-describedby': instructionsId,
    'data-kind': kind,
    'data-loading': 'true',
  });
  board.style.aspectRatio = `${crop.width} / ${crop.height}`;
  element.append(instructions, board);

  // The sliding gap's tile is the hidden piece; it is never drawn.
  const hidden = kind === 'sliding' ? definition.rules['empty'] : undefined;
  const buttons = new Map<string, HTMLButtonElement>();
  for (const tile of definition.tiles) {
    if (tile.id === hidden) continue;
    const button = el(doc, 'button', { type: 'button', class: 'tile', part: 'tile', tabindex: '-1', 'data-tile': tile.id });
    Object.assign(button.style, backgroundFor(imageUrl, tile.rect, source), {
      width: pct(100 / cols),
      height: pct(100 / rows),
    });
    if (definition.assistance.numbers) {
      button.append(el(doc, 'span', { class: 'number', 'aria-hidden': 'true' }, String(tile.home + 1)));
    }
    buttons.set(tile.id, button);
    board.append(button);
  }
  const marker = el(doc, 'div', { class: 'marker', hidden: '' });
  const preview = el(doc, 'div', { class: 'preview', part: 'preview', hidden: '' });
  Object.assign(preview.style, backgroundFor(imageUrl, crop, source), { inset: '0' });
  board.append(marker, preview);

  const image = loadImage(
    imageUrl,
    () => {
      board.setAttribute('data-loading', 'false');
    },
    () => {
      ctx.assetError(imageUrl);
    },
  );

  let layout = layoutOf(kind, definition.tiles, ctx.engine.exportState().board);
  let focusId: string = definition.tiles[0]?.id ?? '';
  let selected: string | null = null;
  let target: number | null = null;
  const angles = new Map<string, number>();
  let hintTimer: ReturnType<typeof setTimeout> | null = null;
  let drag: { id: string; pointer: number; x: number; y: number; active: boolean } | null = null;
  let suppressClick = false;

  const positionOf = (id: string): number => layout.cells.indexOf(id);
  const rowCol = (pos: number): [number, number] => [Math.floor(pos / cols), pos % cols];
  const isCorrect = (tile: Tile, pos: number): boolean =>
    kind === 'rotation' ? (layout.orientation.get(tile.id) ?? 0) === 0 : pos === tile.home;

  function label(tile: Tile, pos: number): string {
    const m = ctx.messages();
    const [row, col] = rowCol(pos);
    let text =
      kind === 'strips'
        ? format(m.strip, { n: tile.home + 1, position: pos + 1, count: layout.cells.length })
        : format(m.tile, { n: tile.home + 1, row: row + 1, col: col + 1 });
    if (kind === 'rotation') {
      text += `, ${format(m.orientation, { degrees: layout.orientation.get(tile.id) ?? 0 })}`;
    }
    return text;
  }

  function render(): void {
    if (!buttons.has(focusId) || positionOf(focusId) < 0) {
      focusId = layout.cells.find((id): id is string => id !== null) ?? focusId;
    }
    for (const [id, button] of buttons) {
      const tile = tilesById.get(id) as Tile;
      const pos = positionOf(id);
      const [row, col] = rowCol(pos);
      button.style.left = pct((col * 100) / cols);
      button.style.top = pct((row * 100) / rows);
      if (kind === 'rotation') {
        const degrees = layout.orientation.get(id) ?? 0;
        const previous = angles.get(id) ?? degrees;
        const delta = ((((degrees - previous) % 360) + 540) % 360) - 180;
        angles.set(id, previous + delta);
        button.style.transform = `rotate(${previous + delta}deg)`;
      }
      button.setAttribute('aria-label', label(tile, pos));
      button.tabIndex = id === focusId ? 0 : -1;
      if (kind === 'swap' || kind === 'strips') {
        button.setAttribute('aria-pressed', String(selected === id));
      }
      button.classList.toggle('is-correct', definition.assistance.highlightCorrect && isCorrect(tile, pos));
      button.disabled = ctx.engine.status === 'complete';
    }
    renderMarker();
  }

  function renderMarker(): void {
    if (kind !== 'strips' || target === null || selected === null) {
      marker.hidden = true;
      return;
    }
    marker.hidden = false;
    const from = positionOf(selected);
    // The strip is inserted at `target`; draw the insertion edge on that side.
    const edge = target > from ? target + 1 : target;
    if (rows > 1) {
      Object.assign(marker.style, { left: '0', width: '100%', height: '4px', top: `calc(${pct((edge * 100) / rows)} - 2px)` });
    } else {
      Object.assign(marker.style, { top: '0', height: '100%', width: '4px', left: `calc(${pct((edge * 100) / cols)} - 2px)` });
    }
  }

  function clearSelection(): void {
    selected = null;
    target = null;
  }

  function act(action: Action, announcement: string): void {
    if (ctx.dispatch(action)) {
      ctx.announce(announcement);
    } else {
      ctx.announce(ctx.messages().illegal);
    }
  }

  function activate(id: string, ccw: boolean): void {
    const tile = tilesById.get(id);
    if (tile === undefined || ctx.engine.status === 'complete') return;
    const m = ctx.messages();
    const n = tile.home + 1;
    if (kind === 'sliding') {
      act({ type: 'slide', tile: id }, format(m.moved, { n }));
    } else if (kind === 'rotation') {
      const ok = ctx.dispatch({ type: 'rotate', tile: id, direction: ccw ? 'ccw' : 'cw' });
      ctx.announce(ok ? format(m.rotated, { n, degrees: layout.orientation.get(id) ?? 0 }) : m.illegal);
    } else if (selected === null) {
      selected = id;
      target = positionOf(id);
      render();
      ctx.announce(format(m.tileSelected, { n }));
    } else if (selected === id && (kind === 'swap' || target === positionOf(id))) {
      clearSelection();
      render();
    } else if (kind === 'swap') {
      const other = tilesById.get(selected) as Tile;
      clearSelection();
      act({ type: 'swap', a: other.id, b: id }, format(m.swapped, { a: other.home + 1, b: n }));
    } else {
      const strip = tilesById.get(selected) as Tile;
      const to = selected === id ? (target ?? positionOf(id)) : positionOf(id);
      clearSelection();
      act({ type: 'move', strip: strip.id, to }, format(m.moved, { n: strip.home + 1 }));
    }
  }

  function neighbour(pos: number, key: string): number | null {
    const [row, col] = rowCol(pos);
    const delta: Record<string, [number, number]> = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] };
    const step = delta[key];
    if (step === undefined) return null;
    let [r, c] = [row + step[0], col + step[1]];
    // Skip over the sliding gap.
    if (r >= 0 && r < rows && c >= 0 && c < cols && layout.cells[r * cols + c] === null) {
      [r, c] = [r + step[0], c + step[1]];
    }
    return r >= 0 && r < rows && c >= 0 && c < cols ? r * cols + c : null;
  }

  function onKeyDown(event: KeyboardEvent): void {
    const key = event.key;
    focusId = tileFrom(event) ?? focusId;
    if (key === 'Enter' || key === ' ') {
      event.preventDefault();
      activate(focusId, event.shiftKey);
      return;
    }
    if (key === 'Escape' && selected !== null) {
      event.preventDefault();
      clearSelection();
      render();
      return;
    }
    if (key === 'Home' || key === 'End') {
      event.preventDefault();
      const ids = layout.cells.filter((id): id is string => id !== null);
      moveFocus((key === 'Home' ? ids[0] : ids[ids.length - 1]) ?? focusId);
      return;
    }
    if (kind === 'strips' && selected !== null && target !== null) {
      const next = neighbour(target, key);
      if (next !== null || key.startsWith('Arrow')) event.preventDefault();
      if (next !== null) {
        target = next;
        renderMarker();
        ctx.announce(
          format(ctx.messages().strip, { n: (tilesById.get(selected)?.home ?? 0) + 1, position: next + 1, count: layout.cells.length }),
        );
      }
      return;
    }
    const next = neighbour(positionOf(focusId), key);
    if (key.startsWith('Arrow')) event.preventDefault();
    const id = next === null ? null : layout.cells[next];
    if (id !== null && id !== undefined) moveFocus(id);
  }

  function moveFocus(id: string): void {
    focusId = id;
    render();
    buttons.get(id)?.focus();
  }

  function cellAt(clientX: number, clientY: number): number | null {
    const rect = board.getBoundingClientRect();
    const col = Math.floor(((clientX - rect.left) / rect.width) * cols);
    const row = Math.floor(((clientY - rect.top) / rect.height) * rows);
    return row >= 0 && row < rows && col >= 0 && col < cols ? row * cols + col : null;
  }

  function tileFrom(event: Event): string | null {
    const button = (event.target as Element | null)?.closest('button.tile');
    return button instanceof HTMLElement && board.contains(button) ? (button.dataset['tile'] ?? null) : null;
  }

  function onPointerDown(event: PointerEvent): void {
    const id = tileFrom(event);
    if (id === null || event.button !== 0 || (kind !== 'swap' && kind !== 'strips')) return;
    drag = { id, pointer: event.pointerId, x: event.clientX, y: event.clientY, active: false };
  }

  function onPointerMove(event: PointerEvent): void {
    if (drag === null || event.pointerId !== drag.pointer) return;
    const dx = event.clientX - drag.x;
    const dy = event.clientY - drag.y;
    const button = buttons.get(drag.id);
    if (button === undefined) return;
    if (!drag.active) {
      if (Math.hypot(dx, dy) < DRAG_THRESHOLD) return;
      drag.active = true;
      button.setPointerCapture(event.pointerId);
      button.classList.add('is-dragging');
      clearSelection();
    }
    button.style.transform = kind === 'strips' ? (rows > 1 ? `translateY(${dy}px)` : `translateX(${dx}px)`) : `translate(${dx}px, ${dy}px)`;
    const cell = cellAt(event.clientX, event.clientY);
    for (const [id, other] of buttons)
      other.classList.toggle('is-target', kind === 'swap' && cell !== null && layout.cells[cell] === id && id !== drag.id);
    if (kind === 'strips') {
      selected = drag.id;
      target = cell;
      renderMarker();
      selected = null;
    }
  }

  function endDrag(event: PointerEvent, commit: boolean): void {
    if (drag === null || event.pointerId !== drag.pointer) return;
    const { id, active } = drag;
    drag = null;
    if (!active) return;
    suppressClick = true;
    const button = buttons.get(id);
    button?.classList.remove('is-dragging');
    if (button !== undefined) button.style.transform = '';
    for (const other of buttons.values()) other.classList.remove('is-target');
    marker.hidden = true;
    target = null;
    const cell = commit ? cellAt(event.clientX, event.clientY) : null;
    const tile = tilesById.get(id) as Tile;
    const m = ctx.messages();
    if (cell === null || cell === positionOf(id)) return;
    if (kind === 'swap') {
      const other = tilesById.get(layout.cells[cell] ?? '');
      if (other !== undefined) act({ type: 'swap', a: id, b: other.id }, format(m.swapped, { a: tile.home + 1, b: other.home + 1 }));
    } else {
      act({ type: 'move', strip: id, to: cell }, format(m.moved, { n: tile.home + 1 }));
    }
  }

  function onClick(event: MouseEvent): void {
    if (suppressClick) {
      suppressClick = false;
      return;
    }
    const id = tileFrom(event);
    if (id === null) return;
    focusId = id;
    activate(id, event.shiftKey);
  }

  const onUp = (event: PointerEvent): void => {
    endDrag(event, true);
  };
  const onCancel = (event: PointerEvent): void => {
    endDrag(event, false);
  };
  board.addEventListener('keydown', onKeyDown);
  board.addEventListener('click', onClick);
  board.addEventListener('pointerdown', onPointerDown);
  board.addEventListener('pointermove', onPointerMove);
  board.addEventListener('pointerup', onUp);
  board.addEventListener('pointercancel', onCancel);
  render();

  return {
    element,
    update(next, external) {
      layout = layoutOf(kind, definition.tiles, next);
      if (external) clearSelection();
      render();
    },
    showHint(action) {
      const id = 'tile' in action ? action.tile : 'strip' in action ? action.strip : 'a' in action ? action.a : null;
      const button = id === null ? undefined : buttons.get(id);
      if (button === undefined) return;
      for (const other of buttons.values()) other.classList.remove('is-hint');
      button.classList.add('is-hint');
      if (hintTimer !== null) clearTimeout(hintTimer);
      hintTimer = setTimeout(() => {
        button.classList.remove('is-hint');
      }, 2500);
      if (id !== null) moveFocus(id);
    },
    setPreview(visible) {
      preview.hidden = !visible;
    },
    focus() {
      buttons.get(focusId)?.focus();
    },
    destroy() {
      if (hintTimer !== null) clearTimeout(hintTimer);
      image.cancel();
      board.removeEventListener('keydown', onKeyDown);
      board.removeEventListener('click', onClick);
      board.removeEventListener('pointerdown', onPointerDown);
      board.removeEventListener('pointermove', onPointerMove);
      board.removeEventListener('pointerup', onUp);
      board.removeEventListener('pointercancel', onCancel);
      element.remove();
    },
  };
}
